package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"
)

var embeddedAPIKey string

// testDialContext is nil in production. It lets the test suite exercise complete
// TLS httptest servers on hosts where opening even a loopback socket is forbidden.
var testDialContext func(context.Context, string, string) (net.Conn, error)

const (
	maxInputBytes = 1 << 20
	maxPageBytes  = 32 << 20
	maxTotalBytes = 128 << 20
	defaultPages  = 1000
	defaultPeople = 1000000
)

var patientIDPattern = regexp.MustCompile(`^[A-Za-z0-9.-]{1,64}$`)

type input struct {
	BaseURL        string `json:"base_url"`
	Token          string `json:"token"`
	ContextID      string `json:"context_id"`
	TLSVerify      *bool  `json:"tls_verify"`
	CAFile         string `json:"ca_file"`
	TimeoutSeconds int    `json:"timeout_seconds"`
	MaxPages       int    `json:"max_pages"`
	MaxPatients    int    `json:"max_patients"`
}

type patient struct {
	ID            string   `json:"id"`
	PatientNumber *int64   `json:"patient_number"`
	FirstName     string   `json:"first_name"`
	LastName      string   `json:"last_name"`
	Phones        []string `json:"phones"`
}

type protocolLine struct {
	Type         string    `json:"type"`
	Code         string    `json:"code,omitempty"`
	Pages        int       `json:"pages,omitempty"`
	PatientCount int       `json:"patient_count,omitempty"`
	Total        int       `json:"total,omitempty"`
	Patients     []patient `json:"patients,omitempty"`
}

type importError string

func (e importError) Error() string { return string(e) }

func fail(code string) error { return importError(code) }

func main() {
	if err := run(os.Stdin, os.Stdout); err != nil {
		os.Exit(1)
	}
}

func run(in io.Reader, out io.Writer) error {
	enc := json.NewEncoder(out)
	writeError := func(code string) error {
		_ = enc.Encode(struct {
			Type string `json:"type"`
			Code string `json:"code"`
		}{Type: "error", Code: code})
		return fail(code)
	}

	cfg, err := decodeInput(in)
	if err != nil {
		return writeError("config")
	}
	if embeddedAPIKey == "" {
		return writeError("config")
	}

	base, err := validateConfig(&cfg)
	if err != nil {
		return writeError("config")
	}
	client, err := makeClient(cfg)
	if err != nil {
		return writeError("config")
	}

	ctx, cancel := context.WithTimeout(context.Background(), 600*time.Second)
	defer cancel()
	patients, pages, total, err := importPatients(ctx, client, base, cfg, enc)
	if err != nil {
		code := "invalid_data"
		var typed importError
		if errors.As(err, &typed) {
			code = typed.Error()
		}
		return writeError(code)
	}
	if err := enc.Encode(struct {
		Type     string    `json:"type"`
		Pages    int       `json:"pages"`
		Total    int       `json:"total"`
		Patients []patient `json:"patients"`
	}{Type: "complete", Pages: pages, Total: total, Patients: patients}); err != nil {
		return err
	}
	return nil
}

func decodeInput(r io.Reader) (input, error) {
	var cfg input
	lr := &io.LimitedReader{R: r, N: maxInputBytes + 1}
	dec := json.NewDecoder(lr)
	dec.DisallowUnknownFields()
	if err := dec.Decode(&cfg); err != nil {
		return cfg, err
	}
	var extra json.RawMessage
	if err := dec.Decode(&extra); err != io.EOF {
		return cfg, errors.New("extra input")
	}
	if lr.N <= 0 {
		return cfg, errors.New("input too large")
	}
	return cfg, nil
}

func validateConfig(cfg *input) (*url.URL, error) {
	if cfg.TLSVerify == nil {
		v := true
		cfg.TLSVerify = &v
	}
	if cfg.TimeoutSeconds == 0 {
		cfg.TimeoutSeconds = 30
	}
	if cfg.MaxPages == 0 {
		cfg.MaxPages = defaultPages
	}
	if cfg.MaxPatients == 0 {
		cfg.MaxPatients = defaultPeople
	}
	if cfg.TimeoutSeconds < 1 || cfg.TimeoutSeconds > 120 || cfg.MaxPages < 1 || cfg.MaxPatients < 1 {
		return nil, errors.New("invalid limits")
	}
	if !safeOpaque(cfg.Token, 16384, false) || !safeOpaque(cfg.ContextID, 512, false) || !safeOpaque(cfg.CAFile, 4096, true) || len(cfg.BaseURL) > 8192 {
		return nil, errors.New("invalid credentials")
	}
	u, err := url.Parse(cfg.BaseURL)
	if err != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" {
		return nil, errors.New("invalid base URL")
	}
	if u.Port() != "" {
		if _, err := strconv.ParseUint(u.Port(), 10, 16); err != nil {
			return nil, errors.New("invalid port")
		}
	}
	if invalidPath(u) {
		return nil, errors.New("invalid base path")
	}
	u.Path = strings.TrimSuffix(u.Path, "/")
	u.RawPath = ""
	return u, nil
}

func safeOpaque(s string, max int, allowEmpty bool) bool {
	if (!allowEmpty && s == "") || !utf8.ValidString(s) || utf8.RuneCountInString(s) > max {
		return false
	}
	for _, r := range s {
		if unicode.IsControl(r) {
			return false
		}
	}
	return true
}

func makeClient(cfg input) (*http.Client, error) {
	tlsConfig := &tls.Config{MinVersion: tls.VersionTLS12, InsecureSkipVerify: !*cfg.TLSVerify} // #nosec G402 -- explicit opt-out is part of the request schema.
	if cfg.CAFile != "" {
		if !*cfg.TLSVerify {
			return nil, errors.New("CA with disabled verification")
		}
		pem, err := os.ReadFile(cfg.CAFile)
		if err != nil {
			return nil, err
		}
		roots, err := x509.SystemCertPool()
		if err != nil || roots == nil {
			roots = x509.NewCertPool()
		}
		if !roots.AppendCertsFromPEM(pem) {
			return nil, errors.New("invalid CA")
		}
		tlsConfig.RootCAs = roots
	}
	dialContext := (&net.Dialer{Timeout: time.Duration(cfg.TimeoutSeconds) * time.Second, KeepAlive: 30 * time.Second}).DialContext
	if testDialContext != nil {
		dialContext = testDialContext
	}
	transport := &http.Transport{
		Proxy:                 nil,
		TLSClientConfig:       tlsConfig,
		DialContext:           dialContext,
		TLSHandshakeTimeout:   time.Duration(cfg.TimeoutSeconds) * time.Second,
		ResponseHeaderTimeout: time.Duration(cfg.TimeoutSeconds) * time.Second,
		ForceAttemptHTTP2:     true,
	}
	return &http.Client{
		Transport: transport,
		Timeout:   time.Duration(cfg.TimeoutSeconds) * time.Second,
		CheckRedirect: func(_ *http.Request, _ []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}, nil
}

func importPatients(ctx context.Context, client *http.Client, base *url.URL, cfg input, enc *json.Encoder) ([]patient, int, int, error) {
	patientPath := strings.TrimSuffix(base.Path, "/") + "/Patient"
	first := *base
	first.Path = patientPath
	first.RawPath = ""
	current := &first
	visited := make(map[string]struct{})
	ids := make(map[string]struct{})
	numbers := make(map[int64]string)
	people := make([]patient, 0)
	pages := 0
	totalBytes := int64(0)
	knownTotal := -1

	for current != nil {
		canonicalURL := *current
		canonicalURL.RawQuery = current.Query().Encode()
		canonical := canonicalURL.String()
		if _, ok := visited[canonical]; ok {
			return nil, pages, knownTotal, fail("invalid_data")
		}
		if pages >= cfg.MaxPages {
			return nil, pages, knownTotal, fail("limit")
		}
		visited[canonical] = struct{}{}

		body, err := getPage(ctx, client, current, cfg)
		if err != nil {
			return nil, pages, knownTotal, err
		}
		totalBytes += int64(len(body))
		if totalBytes > maxTotalBytes {
			return nil, pages, knownTotal, fail("limit")
		}
		parsed, err := parseBundle(body)
		if err != nil {
			return nil, pages, knownTotal, fail("invalid_data")
		}
		if parsed.total != nil {
			if knownTotal >= 0 && knownTotal != *parsed.total {
				return nil, pages, knownTotal, fail("invalid_data")
			}
			knownTotal = *parsed.total
			if knownTotal > cfg.MaxPatients {
				return nil, pages, knownTotal, fail("limit")
			}
		}
		for _, p := range parsed.patients {
			if len(people) >= cfg.MaxPatients {
				return nil, pages, knownTotal, fail("limit")
			}
			if _, exists := ids[p.ID]; exists {
				return nil, pages, knownTotal, fail("invalid_data")
			}
			ids[p.ID] = struct{}{}
			if p.PatientNumber != nil {
				if other, exists := numbers[*p.PatientNumber]; exists && other != p.ID {
					return nil, pages, knownTotal, fail("invalid_data")
				}
				numbers[*p.PatientNumber] = p.ID
			}
			people = append(people, p)
		}
		pages++
		if err := enc.Encode(struct {
			Type         string `json:"type"`
			Pages        int    `json:"pages"`
			PatientCount int    `json:"patient_count"`
			Total        int    `json:"total"`
		}{Type: "progress", Pages: pages, PatientCount: len(people), Total: knownTotal}); err != nil {
			return nil, pages, knownTotal, err
		}
		if parsed.next == "" {
			current = nil
			continue
		}
		next, err := resolveNext(current, parsed.next, base, patientPath)
		if err != nil {
			return nil, pages, knownTotal, fail("invalid_data")
		}
		current = next
	}
	if knownTotal < 0 || len(people) != knownTotal {
		return nil, pages, knownTotal, fail("invalid_data")
	}
	return people, pages, knownTotal, nil
}

func getPage(ctx context.Context, client *http.Client, u *url.URL, cfg input) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return nil, fail("invalid_data")
	}
	req.Header.Set("X-API-Key", embeddedAPIKey)
	req.Header.Set("Authorization", "Bearer "+cfg.Token)
	req.Header.Set("Accept", "application/fhir+json")
	req.Header.Set("X-TreatWarningAsError", "true")
	req.Header.Set("X-FHIR-Profile", "https://fhir.t2med.de/StructureDefinition/FhirApiPatient|1.0.0")
	req.Header.Set("User-Agent", "kienzlefon-t2med")
	resp, err := client.Do(req)
	if err != nil {
		return nil, fail("network")
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
		return nil, fail("auth")
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		if resp.StatusCode >= 500 {
			return nil, fail("network")
		}
		return nil, fail("invalid_data")
	}
	lr := &io.LimitedReader{R: resp.Body, N: maxPageBytes + 1}
	body, err := io.ReadAll(lr)
	if err != nil {
		return nil, fail("network")
	}
	if len(body) > maxPageBytes {
		return nil, fail("limit")
	}
	return body, nil
}

type parsedBundle struct {
	total    *int
	next     string
	patients []patient
}

func parseBundle(data []byte) (parsedBundle, error) {
	var top struct {
		ResourceType string          `json:"resourceType"`
		Type         string          `json:"type"`
		Total        json.RawMessage `json:"total"`
		Entry        json.RawMessage `json:"entry"`
		Link         json.RawMessage `json:"link"`
	}
	if err := decodeSingle(data, &top); err != nil || top.ResourceType != "Bundle" || top.Type != "searchset" {
		return parsedBundle{}, errors.New("not search bundle")
	}
	result := parsedBundle{patients: make([]patient, 0)}
	if len(top.Total) != 0 {
		var total int
		if bytes.Equal(bytes.TrimSpace(top.Total), []byte("null")) || json.Unmarshal(top.Total, &total) != nil || total < 0 {
			return result, errors.New("invalid total")
		}
		result.total = &total
	}
	if len(top.Entry) != 0 {
		if bytes.Equal(bytes.TrimSpace(top.Entry), []byte("null")) {
			return result, errors.New("null entry")
		}
		var entries []json.RawMessage
		if err := json.Unmarshal(top.Entry, &entries); err != nil {
			return result, errors.New("invalid entry")
		}
		for _, raw := range entries {
			p, include, err := parseEntry(raw)
			if err != nil {
				return result, err
			}
			if !include {
				continue
			}
			result.patients = append(result.patients, p)
		}
	}
	if len(top.Link) != 0 {
		if bytes.Equal(bytes.TrimSpace(top.Link), []byte("null")) {
			return result, errors.New("null link")
		}
		var links []struct {
			Relation string `json:"relation"`
			URL      string `json:"url"`
		}
		if err := json.Unmarshal(top.Link, &links); err != nil {
			return result, errors.New("invalid links")
		}
		for _, link := range links {
			if link.Relation == "" || link.URL == "" || !safeOpaque(link.Relation, 64, false) || !safeOpaque(link.URL, 8192, false) {
				return result, errors.New("invalid link")
			}
			if link.Relation == "next" {
				if result.next != "" {
					return result, errors.New("multiple next links")
				}
				result.next = link.URL
			}
		}
	}
	return result, nil
}

func parseEntry(raw json.RawMessage) (patient, bool, error) {
	var entry struct {
		Resource json.RawMessage `json:"resource"`
		Search   json.RawMessage `json:"search"`
	}
	if err := json.Unmarshal(raw, &entry); err != nil || len(entry.Resource) == 0 || bytes.Equal(bytes.TrimSpace(entry.Resource), []byte("null")) {
		return patient{}, false, errors.New("invalid entry object")
	}
	mode := ""
	if len(entry.Search) != 0 {
		if bytes.Equal(bytes.TrimSpace(entry.Search), []byte("null")) {
			return patient{}, false, errors.New("invalid search metadata")
		}
		var search struct {
			Mode string `json:"mode"`
		}
		if err := json.Unmarshal(entry.Search, &search); err != nil {
			return patient{}, false, errors.New("invalid search metadata")
		}
		mode = search.Mode
		if mode != "" && mode != "match" && mode != "include" && mode != "outcome" {
			return patient{}, false, errors.New("invalid search mode")
		}
	}
	var kind struct {
		ResourceType string `json:"resourceType"`
	}
	if err := json.Unmarshal(entry.Resource, &kind); err != nil || kind.ResourceType == "" {
		return patient{}, false, errors.New("invalid resource")
	}
	if kind.ResourceType == "OperationOutcome" {
		if err := checkOutcome(entry.Resource); err != nil {
			return patient{}, false, err
		}
		return patient{}, false, nil
	}
	if mode == "outcome" {
		return patient{}, false, errors.New("non-outcome resource")
	}
	if mode == "include" {
		return patient{}, false, nil
	}
	if kind.ResourceType != "Patient" {
		return patient{}, false, errors.New("unexpected match resource")
	}
	p, err := parsePatient(entry.Resource)
	return p, err == nil, err
}

func checkOutcome(raw json.RawMessage) error {
	var outcome struct {
		Issue json.RawMessage `json:"issue"`
	}
	if err := json.Unmarshal(raw, &outcome); err != nil || len(outcome.Issue) == 0 || bytes.Equal(bytes.TrimSpace(outcome.Issue), []byte("null")) {
		return errors.New("invalid outcome")
	}
	var issues []struct {
		Severity string `json:"severity"`
	}
	if err := json.Unmarshal(outcome.Issue, &issues); err != nil || len(issues) == 0 {
		return errors.New("invalid outcome issues")
	}
	for _, issue := range issues {
		switch issue.Severity {
		case "fatal", "error":
			return errors.New("outcome error")
		case "warning", "information":
		default:
			return errors.New("invalid outcome severity")
		}
	}
	return nil
}

func parsePatient(raw json.RawMessage) (patient, error) {
	var resource struct {
		ID         string          `json:"id"`
		Identifier json.RawMessage `json:"identifier"`
		Name       json.RawMessage `json:"name"`
		Telecom    json.RawMessage `json:"telecom"`
	}
	if err := json.Unmarshal(raw, &resource); err != nil || !patientIDPattern.MatchString(resource.ID) {
		return patient{}, errors.New("invalid patient")
	}
	p := patient{ID: resource.ID, Phones: make([]string, 0)}
	var err error
	p.PatientNumber, err = parsePatientNumber(rawPointer(resource.Identifier))
	if err != nil {
		return patient{}, err
	}
	p.FirstName, p.LastName, err = parseName(rawPointer(resource.Name))
	if err != nil {
		return patient{}, err
	}
	p.Phones, err = parsePhones(rawPointer(resource.Telecom))
	if err != nil {
		return patient{}, err
	}
	return p, nil
}

func rawPointer(raw json.RawMessage) *json.RawMessage {
	if len(raw) == 0 {
		return nil
	}
	return &raw
}

func parsePatientNumber(raw *json.RawMessage) (*int64, error) {
	if raw == nil {
		return nil, nil
	}
	if bytes.Equal(bytes.TrimSpace(*raw), []byte("null")) {
		return nil, errors.New("null identifier")
	}
	var identifiers []struct {
		System string `json:"system"`
		Value  string `json:"value"`
	}
	if err := json.Unmarshal(*raw, &identifiers); err != nil {
		return nil, errors.New("invalid identifier list")
	}
	values := make(map[string]struct{})
	for _, id := range identifiers {
		if id.System == "https://fhir.t2med.de/identifier/patientennummer" {
			value := strings.TrimSpace(id.Value)
			values[value] = struct{}{}
		}
	}
	if len(values) != 1 {
		return nil, nil
	}
	var value string
	for value = range values {
	}
	if value == "" {
		return nil, nil
	}
	for _, r := range value {
		if r < '0' || r > '9' {
			return nil, nil
		}
	}
	n, err := strconv.ParseInt(value, 10, 64)
	if err != nil || n < 1 || n > 999999999999 {
		return nil, nil
	}
	return &n, nil
}

type humanName struct {
	Use    string   `json:"use"`
	Text   string   `json:"text"`
	Family string   `json:"family"`
	Given  []string `json:"given"`
	Period struct {
		Start string `json:"start"`
		End   string `json:"end"`
	} `json:"period"`
}

func parseName(raw *json.RawMessage) (string, string, error) {
	if raw == nil {
		return "", "", nil
	}
	if bytes.Equal(bytes.TrimSpace(*raw), []byte("null")) {
		return "", "", errors.New("null name")
	}
	var names []humanName
	if err := json.Unmarshal(*raw, &names); err != nil {
		return "", "", errors.New("invalid names")
	}
	now := time.Now()
	var structured, fallback *humanName
	for rank := 0; rank < 3 && structured == nil; rank++ {
		for i := range names {
			n := &names[i]
			if !currentName(*n, now) || nameRank(n.Use) != rank {
				continue
			}
			if fallback == nil && strings.TrimSpace(n.Text) != "" {
				fallback = n
			}
			if strings.TrimSpace(n.Family) != "" || nonemptyGiven(n.Given) {
				structured = n
				break
			}
		}
	}
	if structured != nil {
		given := make([]string, 0, len(structured.Given))
		for _, value := range structured.Given {
			value, err := cleanFHIRString(value)
			if err != nil {
				return "", "", err
			}
			if value != "" {
				given = append(given, value)
			}
		}
		first, err := cleanFHIRString(strings.Join(given, " "))
		if err != nil {
			return "", "", err
		}
		last, err := cleanFHIRString(structured.Family)
		return first, last, err
	}
	if fallback != nil {
		text, err := cleanFHIRString(fallback.Text)
		return "", text, err
	}
	return "", "", nil
}

func nameRank(use string) int {
	switch use {
	case "official":
		return 0
	case "usual":
		return 1
	case "old", "maiden":
		return 99
	default:
		return 2
	}
}

func currentName(n humanName, now time.Time) bool {
	if nameRank(n.Use) == 99 {
		return false
	}
	if n.Period.Start != "" {
		start, err := parseFHIRDate(n.Period.Start, false)
		if err != nil || start.After(now) {
			return false
		}
	}
	if n.Period.End != "" {
		end, err := parseFHIRDate(n.Period.End, true)
		if err != nil || end.Before(now) {
			return false
		}
	}
	return true
}

func parseFHIRDate(value string, end bool) (time.Time, error) {
	layouts := []string{time.RFC3339Nano, "2006-01-02", "2006-01", "2006"}
	for i, layout := range layouts {
		if t, err := time.Parse(layout, value); err == nil {
			if end && i > 0 {
				switch layout {
				case "2006":
					t = t.AddDate(1, 0, 0).Add(-time.Nanosecond)
				case "2006-01":
					t = t.AddDate(0, 1, 0).Add(-time.Nanosecond)
				case "2006-01-02":
					t = t.AddDate(0, 0, 1).Add(-time.Nanosecond)
				}
			}
			return t, nil
		}
	}
	return time.Time{}, errors.New("invalid date")
}

func nonemptyGiven(values []string) bool {
	for _, value := range values {
		if strings.TrimSpace(value) != "" {
			return true
		}
	}
	return false
}

func parsePhones(raw *json.RawMessage) ([]string, error) {
	phones := make([]string, 0)
	if raw == nil {
		return phones, nil
	}
	if bytes.Equal(bytes.TrimSpace(*raw), []byte("null")) {
		return nil, errors.New("null telecom")
	}
	var points []struct {
		System string `json:"system"`
		Value  string `json:"value"`
	}
	if err := json.Unmarshal(*raw, &points); err != nil {
		return nil, errors.New("invalid telecom")
	}
	for _, point := range points {
		if point.System != "phone" {
			continue
		}
		_, err := cleanFHIRString(point.Value)
		if err != nil {
			return nil, err
		}
		phones = append(phones, point.Value)
	}
	return phones, nil
}

func cleanFHIRString(value string) (string, error) {
	if !utf8.ValidString(value) {
		return "", errors.New("invalid UTF-8")
	}
	for _, r := range value {
		if unicode.IsControl(r) {
			return "", errors.New("control character")
		}
	}
	value = strings.TrimSpace(value)
	if utf8.RuneCountInString(value) > 512 {
		return "", errors.New("string too long")
	}
	return value, nil
}

func resolveNext(current *url.URL, reference string, base *url.URL, patientPath string) (*url.URL, error) {
	rel, err := url.Parse(reference)
	if err != nil || rel.User != nil || rel.Fragment != "" {
		return nil, errors.New("invalid next URL")
	}
	next := current.ResolveReference(rel)
	if next.Scheme != "https" || !strings.EqualFold(next.Hostname(), base.Hostname()) || effectivePort(next) != effectivePort(base) || next.User != nil || next.Fragment != "" {
		return nil, errors.New("foreign next URL")
	}
	query, err := url.ParseQuery(next.RawQuery)
	if err != nil || invalidPath(next) || hasCredentialQuery(query) {
		return nil, errors.New("unsafe next URL")
	}
	basePath := strings.TrimSuffix(base.Path, "/")
	allowed := next.Path == patientPath
	if next.Path == basePath || next.Path == basePath+"/" {
		allowed = hasGetPages(query)
	}
	if !allowed {
		return nil, errors.New("unexpected next path")
	}
	return next, nil
}

func effectivePort(u *url.URL) string {
	if u.Port() != "" {
		return u.Port()
	}
	return "443"
}

func invalidPath(u *url.URL) bool {
	if strings.Contains(u.EscapedPath(), "\\") || strings.Contains(u.Path, "\\") {
		return true
	}
	decoded := u.EscapedPath()
	for i := 0; i < 16; i++ {
		value, err := url.PathUnescape(decoded)
		if err != nil {
			return true
		}
		if value == decoded {
			break
		}
		decoded = value
		if i == 15 {
			return true
		}
	}
	if strings.Contains(decoded, "\\") || strings.Contains(decoded, "//") {
		return true
	}
	if decoded == "" {
		return false
	}
	for _, segment := range strings.Split(decoded, "/") {
		if segment == "." || segment == ".." {
			return true
		}
	}
	return strings.TrimSuffix(path.Clean(decoded), "/") != strings.TrimSuffix(decoded, "/")
}

func hasCredentialQuery(values url.Values) bool {
	for key := range values {
		key = strings.ToLower(strings.ReplaceAll(key, "-", "_"))
		if strings.Contains(key, "token") || strings.Contains(key, "key") || strings.Contains(key, "authorization") || strings.Contains(key, "bearer") || strings.Contains(key, "secret") || strings.Contains(key, "password") {
			return true
		}
	}
	return false
}

func hasGetPages(values url.Values) bool {
	for key := range values {
		if strings.HasPrefix(strings.ToLower(key), "_getpages") {
			return true
		}
	}
	return false
}

func decodeSingle(data []byte, target any) error {
	dec := json.NewDecoder(bytes.NewReader(data))
	if err := dec.Decode(target); err != nil {
		return err
	}
	var extra json.RawMessage
	if err := dec.Decode(&extra); err != io.EOF {
		return fmt.Errorf("extra JSON")
	}
	return nil
}
