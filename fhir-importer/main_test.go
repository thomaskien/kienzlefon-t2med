package main

import (
	"bytes"
	"context"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func TestPaginationHeadersAndPatientReduction(t *testing.T) {
	oldKey := embeddedAPIKey
	embeddedAPIKey = "test-api-key"
	t.Cleanup(func() { embeddedAPIKey = oldKey })

	var requests atomic.Int32
	server := newTLSServer(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests.Add(1)
		if r.Method != http.MethodGet || r.Header.Get("X-API-Key") != "test-api-key" || r.Header.Get("Authorization") != "Bearer test-token" {
			t.Errorf("unexpected request method or credentials")
		}
		if r.Header.Get("Accept") != "application/fhir+json" || r.Header.Get("X-TreatWarningAsError") != "true" || r.Header.Get("User-Agent") != "kienzlefon-t2med" {
			t.Errorf("required headers missing: %#v", r.Header)
		}
		if strings.Contains(r.URL.RawQuery, "context") {
			t.Errorf("context leaked into query")
		}
		w.Header().Set("Content-Type", "application/fhir+json")
		switch {
		case r.URL.Path == "/fhir/Patient" && r.URL.Query().Get("page") == "":
			io.WriteString(w, `{"resourceType":"Bundle","type":"searchset","total":3,"entry":[{"search":{"mode":"match"},"resource":{"resourceType":"Patient","id":"fhir.A","identifier":[{"system":"https://fhir.t2med.de/identifier/patientennummer","value":"00042"}],"name":[{"use":"official","family":" Beispiel ","given":[" Ada ","Lovelace"]}],"telecom":[{"system":"phone","value":" +49 (0) 30 / 12-3 "},{"system":"email","value":"no@example.test"}]}}],"link":[{"relation":"next","url":"?page=2"}]}`)
		case r.URL.Path == "/fhir/Patient" && r.URL.Query().Get("page") == "2":
			io.WriteString(w, `{"resourceType":"Bundle","type":"searchset","total":3,"entry":[{"resource":{"resourceType":"Patient","id":"fhir-B","identifier":[{"system":"other","value":"987"}],"name":[{"use":"old","family":"Alt"},{"use":"usual","family":"Neu","given":["Bea"]}]}}],"link":[{"relation":"next","url":"/fhir?_getpages=opaque"}]}`)
		case r.URL.Path == "/fhir" && r.URL.Query().Get("_getpages") == "opaque":
			io.WriteString(w, `{"resourceType":"Bundle","type":"searchset","entry":[{"search":{"mode":"include"},"resource":{"resourceType":"Practitioner","id":"ignored"}},{"search":{"mode":"outcome"},"resource":{"resourceType":"OperationOutcome","issue":[{"severity":"warning"}]}},{"resource":{"resourceType":"Patient","id":"third","identifier":[{"system":"https://fhir.t2med.de/identifier/patientennummer","value":"7"},{"system":"https://fhir.t2med.de/identifier/patientennummer","value":"7"}],"name":[{"text":"Textname"}]}}]}`)
		default:
			http.Error(w, "unexpected", http.StatusBadRequest)
		}
	}))
	defer server.Close()

	out, err := runJSON(server.URL+"/fhir", false, "", "test-token", "safe-context", 20, 20)
	if err != nil {
		t.Fatalf("run failed: %v\n%s", err, out)
	}
	lines := decodeLines(t, out)
	if len(lines) != 4 || lines[0].Type != "progress" || lines[2].PatientCount != 3 || lines[3].Type != "complete" {
		t.Fatalf("unexpected protocol: %#v", lines)
	}
	if requests.Load() != 3 {
		t.Fatalf("got %d requests", requests.Load())
	}
	got := lines[3].Patients
	if len(got) != 3 || got[0].ID != "fhir.A" || got[0].PatientNumber == nil || *got[0].PatientNumber != 42 {
		t.Fatalf("first patient incorrectly reduced: %#v", got)
	}
	if got[0].FirstName != "Ada Lovelace" || got[0].LastName != "Beispiel" || len(got[0].Phones) != 1 || got[0].Phones[0] != " +49 (0) 30 / 12-3 " {
		t.Fatalf("name or raw phone changed: %#v", got[0])
	}
	if got[1].PatientNumber != nil || got[1].FirstName != "Bea" || got[1].LastName != "Neu" || len(got[1].Phones) != 0 {
		t.Fatalf("missing identifier/phone handling wrong: %#v", got[1])
	}
	if got[2].ID != "third" || got[2].PatientNumber == nil || *got[2].PatientNumber != 7 || got[2].FirstName != "" || got[2].LastName != "Textname" {
		t.Fatalf("text fallback or FHIR-ID separation wrong: %#v", got[2])
	}
}

func TestCustomCAAndExplicitInsecureTLS(t *testing.T) {
	oldKey := embeddedAPIKey
	embeddedAPIKey = "key"
	t.Cleanup(func() { embeddedAPIKey = oldKey })
	server := newTLSServer(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		io.WriteString(w, `{"resourceType":"Bundle","type":"searchset","total":0}`)
	}))
	defer server.Close()

	cert, err := x509.ParseCertificate(server.TLS.Certificates[0].Certificate[0])
	if err != nil {
		t.Fatal(err)
	}
	caFile := filepath.Join(t.TempDir(), "ca.pem")
	if err := os.WriteFile(caFile, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: cert.Raw}), 0600); err != nil {
		t.Fatal(err)
	}
	if out, err := runJSON(server.URL, true, caFile, "token", "context", 5, 5); err != nil {
		t.Fatalf("custom CA rejected: %v %s", err, out)
	}
	if out, err := runJSON(server.URL, false, "", "token", "context", 5, 5); err != nil {
		t.Fatalf("explicit insecure TLS rejected: %v %s", err, out)
	}
	if out, err := runJSON(server.URL, true, "", "token", "context", 5, 5); err == nil || lastLine(t, out).Code != "network" {
		t.Fatalf("untrusted TLS unexpectedly accepted: %v %s", err, out)
	}
}

func TestPatientNumberContradictionsAndInvalidValuesBecomeNull(t *testing.T) {
	tests := []string{
		`[]`,
		`[{"system":"https://fhir.t2med.de/identifier/patientennummer","value":"0"}]`,
		`[{"system":"https://fhir.t2med.de/identifier/patientennummer","value":"12x"}]`,
		`[{"system":"https://fhir.t2med.de/identifier/patientennummer","value":"12"},{"system":"https://fhir.t2med.de/identifier/patientennummer","value":"13"}]`,
		`[{"system":"https://fhir.t2med.de/identifier/patientennummer","value":"001"},{"system":"https://fhir.t2med.de/identifier/patientennummer","value":"1"}]`,
	}
	for _, value := range tests {
		raw := json.RawMessage(value)
		number, err := parsePatientNumber(&raw)
		if err != nil || number != nil {
			t.Errorf("expected null number for %s, got %v, %v", value, number, err)
		}
	}
}

func TestDuplicateIDsAndNumbersFail(t *testing.T) {
	patientResource := func(id, number string) string {
		return `{"resourceType":"Patient","id":"` + id + `","identifier":[{"system":"https://fhir.t2med.de/identifier/patientennummer","value":"` + number + `"}]}`
	}
	tests := [][2]string{
		{patientResource("same", "1"), patientResource("same", "1")},
		{patientResource("one", "123"), patientResource("two", "00123")},
	}
	for _, entries := range tests {
		bundle := `{"resourceType":"Bundle","type":"searchset","total":2,"entry":[{"resource":` + entries[0] + `},{"resource":` + entries[1] + `}]}`
		out := runServerBundle(t, bundle)
		if lastLine(t, out).Code != "invalid_data" {
			t.Fatalf("duplicate accepted: %s", out)
		}
	}
}

func TestTotalValidationAndEmptyBundle(t *testing.T) {
	tests := []struct {
		name  string
		body  string
		code  string
		total int
	}{
		{"missing", `{"resourceType":"Bundle","type":"searchset"}`, "invalid_data", 0},
		{"mismatch", `{"resourceType":"Bundle","type":"searchset","total":2,"entry":[{"resource":{"resourceType":"Patient","id":"one"}}]}`, "invalid_data", 0},
		{"empty", `{"resourceType":"Bundle","type":"searchset","total":0}`, "", 0},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			out := runServerBundle(t, test.body)
			last := lastLine(t, out)
			if last.Code != test.code {
				t.Fatalf("got %#v from %s", last, out)
			}
			if test.code == "" && (last.Type != "complete" || last.Total != test.total || last.Patients == nil) {
				t.Fatalf("bad empty completion: %#v", last)
			}
		})
	}
}

func TestInconsistentTotalsAcrossPages(t *testing.T) {
	server := newTLSServer(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("page") == "2" {
			io.WriteString(w, `{"resourceType":"Bundle","type":"searchset","total":3,"entry":[{"resource":{"resourceType":"Patient","id":"two"}}]}`)
			return
		}
		io.WriteString(w, `{"resourceType":"Bundle","type":"searchset","total":2,"entry":[{"resource":{"resourceType":"Patient","id":"one"}}],"link":[{"relation":"next","url":"?page=2"}]}`)
	}))
	defer server.Close()
	out := runAgainst(t, server.URL, false, "", "token", "context", 5, 5)
	if lastLine(t, out).Code != "invalid_data" {
		t.Fatalf("inconsistent totals accepted: %s", out)
	}
}

func TestForeignNextAndRedirectNeverForwardSecrets(t *testing.T) {
	var targetHits atomic.Int32
	target := newTLSServer(t, http.HandlerFunc(func(http.ResponseWriter, *http.Request) { targetHits.Add(1) }))
	defer target.Close()
	for _, response := range []struct {
		status int
		body   string
		loc    string
	}{
		{http.StatusOK, `{"resourceType":"Bundle","type":"searchset","total":0,"link":[{"relation":"next","url":"` + target.URL + `/Patient"}]}`, ""},
		{http.StatusFound, "", target.URL + "/Patient"},
	} {
		server := newTLSServer(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			if response.loc != "" {
				w.Header().Set("Location", response.loc)
			}
			w.WriteHeader(response.status)
			io.WriteString(w, response.body)
		}))
		out := runAgainst(t, server.URL, false, "", "secret-token", "context", 5, 5)
		server.Close()
		if lastLine(t, out).Code != "invalid_data" {
			t.Fatalf("unsafe response not rejected: %s", out)
		}
	}
	if targetHits.Load() != 0 {
		t.Fatalf("foreign target was contacted %d times", targetHits.Load())
	}
}

func TestHTTPStatusTimeoutMalformedAndOutcome(t *testing.T) {
	for _, test := range []struct {
		name   string
		status int
		body   string
		code   string
	}{
		{"unauthorized", 401, `{}`, "auth"},
		{"bad-request", 400, `{}`, "invalid_data"},
		{"malformed", 200, `{"resourceType":"Bundle","type":"searchset","total":0,"entry":null}`, "invalid_data"},
		{"outcome-error", 200, `{"resourceType":"Bundle","type":"searchset","total":0,"entry":[{"search":{"mode":"outcome"},"resource":{"resourceType":"OperationOutcome","issue":[{"severity":"fatal"}]}}]}`, "invalid_data"},
	} {
		t.Run(test.name, func(t *testing.T) {
			server := newTLSServer(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(test.status)
				io.WriteString(w, test.body)
			}))
			defer server.Close()
			out := runAgainst(t, server.URL, false, "", "token", "context", 5, 5)
			if lastLine(t, out).Code != test.code {
				t.Fatalf("got %s", out)
			}
		})
	}

	server := newTLSServer(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		time.Sleep(100 * time.Millisecond)
		io.WriteString(w, `{}`)
	}))
	defer server.Close()
	u, _ := url.Parse(server.URL + "/Patient")
	cfg := input{Token: "token", TimeoutSeconds: 1, TLSVerify: boolPointer(false)}
	client, err := makeClient(cfg)
	if err != nil {
		t.Fatal(err)
	}
	client.Timeout = 10 * time.Millisecond
	if _, err := getPage(context.Background(), client, u, cfg); err == nil || err.Error() != "network" {
		t.Fatalf("timeout not classified as network: %v", err)
	}
}

func TestUnsafeNextFormsAndLimits(t *testing.T) {
	base, _ := url.Parse("https://example.test/fhir")
	current, _ := url.Parse("https://example.test/fhir/Patient?page=1")
	bad := []string{
		"https://example.test/other?page=2",
		"https://user@example.test/fhir/Patient",
		"/fhir/../private",
		"/fhir/%252e%252e/private",
		"/fhir/Patient?access_token=secret",
		"/fhir?_getpages=ok&api-key=secret",
		"/fhir/Patient?one=1;access_token=secret",
	}
	for _, next := range bad {
		if _, err := resolveNext(current, next, base, "/fhir/Patient"); err == nil {
			t.Errorf("unsafe next accepted: %s", next)
		}
	}
	body := `{"resourceType":"Bundle","type":"searchset","total":2,"entry":[{"resource":{"resourceType":"Patient","id":"one"}},{"resource":{"resourceType":"Patient","id":"two"}}]}`
	server := newTLSServer(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { io.WriteString(w, body) }))
	defer server.Close()
	out := runAgainst(t, server.URL, false, "", "token", "context", 1, 1)
	if lastLine(t, out).Code != "limit" {
		t.Fatalf("patient limit not enforced: %s", out)
	}
}

func TestErrorOutputContainsNoSensitiveValues(t *testing.T) {
	oldKey := embeddedAPIKey
	embeddedAPIKey = "TOP-SECRET-KEY"
	t.Cleanup(func() { embeddedAPIKey = oldKey })
	server := newTLSServer(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		io.WriteString(w, `{"resourceType":"Bundle","type":"searchset","total":1,"entry":[{"resource":{"resourceType":"Patient","id":"bad/id","name":[{"family":"Sensitive Name"}]}}]}`)
	}))
	defer server.Close()
	out := runAgainst(t, server.URL, false, "", "TOP-SECRET-TOKEN", "TOP-SECRET-CONTEXT", 5, 5)
	for _, secret := range []string{"TOP-SECRET-KEY", "TOP-SECRET-TOKEN", "TOP-SECRET-CONTEXT", "Sensitive Name", server.URL} {
		if strings.Contains(out, secret) {
			t.Errorf("error output leaked %q: %s", secret, out)
		}
	}
	if lastLine(t, out).Code != "invalid_data" {
		t.Fatalf("unexpected error: %s", out)
	}
}

func TestConfigAndMissingEmbeddedKey(t *testing.T) {
	oldKey := embeddedAPIKey
	embeddedAPIKey = ""
	t.Cleanup(func() { embeddedAPIKey = oldKey })
	var out bytes.Buffer
	err := run(strings.NewReader(`{"base_url":"https://example.test","token":"token","context_id":"ctx","tls_verify":true,"timeout_seconds":1,"max_pages":1,"max_patients":1}`), &out)
	if err == nil || lastLine(t, out.String()).Code != "config" {
		t.Fatalf("missing embedded key accepted: %v %s", err, out.String())
	}
	embeddedAPIKey = "key"
	for _, input := range []string{
		`{}`,
		`{"base_url":"http://example.test","token":"t","context_id":"c"}`,
		`{"base_url":"https://user@example.test","token":"t","context_id":"c"}`,
		`{"base_url":"https://example.test?q=1","token":"t","context_id":"c"}`,
		`{"base_url":"https://example.test","token":"t","context_id":"c","unknown":true}`,
		`{"base_url":"https://example.test","token":"t","context_id":"c"} {}`,
	} {
		out.Reset()
		if err := run(strings.NewReader(input), &out); err == nil || lastLine(t, out.String()).Code != "config" {
			t.Errorf("invalid config accepted: %s => %v %s", input, err, out.String())
		}
	}
}

func runServerBundle(t *testing.T, body string) string {
	t.Helper()
	server := newTLSServer(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { io.WriteString(w, body) }))
	defer server.Close()
	return runAgainst(t, server.URL, false, "", "token", "context", 10, 10)
}

func runAgainst(t *testing.T, base string, verify bool, caFile, token, contextID string, maxPages, maxPatients int) string {
	t.Helper()
	oldKey := embeddedAPIKey
	embeddedAPIKey = "test-key"
	t.Cleanup(func() { embeddedAPIKey = oldKey })
	out, _ := runJSON(base, verify, caFile, token, contextID, maxPages, maxPatients)
	return out
}

func runJSON(base string, verify bool, caFile, token, contextID string, maxPages, maxPatients int) (string, error) {
	request := input{
		BaseURL: base, Token: token, ContextID: contextID, TLSVerify: &verify, CAFile: caFile,
		TimeoutSeconds: 2, MaxPages: maxPages, MaxPatients: maxPatients,
	}
	data, _ := json.Marshal(request)
	var out bytes.Buffer
	err := run(bytes.NewReader(data), &out)
	return out.String(), err
}

func decodeLines(t *testing.T, output string) []protocolLine {
	t.Helper()
	dec := json.NewDecoder(strings.NewReader(output))
	var lines []protocolLine
	for {
		var line protocolLine
		if err := dec.Decode(&line); err == io.EOF {
			break
		} else if err != nil {
			t.Fatalf("invalid NDJSON %q: %v", output, err)
		}
		lines = append(lines, line)
	}
	return lines
}

func lastLine(t *testing.T, output string) protocolLine {
	t.Helper()
	lines := decodeLines(t, output)
	if len(lines) == 0 {
		t.Fatalf("no protocol output")
	}
	return lines[len(lines)-1]
}

func boolPointer(v bool) *bool { return &v }

var (
	pipeServers sync.Map
	pipePort    atomic.Uint32
)

type pipeAddr string

func (a pipeAddr) Network() string { return "tcp" }
func (a pipeAddr) String() string  { return string(a) }

type pipeListener struct {
	address pipeAddr
	accept  chan net.Conn
	done    chan struct{}
	once    sync.Once
}

func newTLSServer(t *testing.T, handler http.Handler) *httptest.Server {
	t.Helper()
	address := pipeAddr("example.com:" + strconv.FormatUint(uint64(40000+pipePort.Add(1)), 10))
	listener := &pipeListener{address: address, accept: make(chan net.Conn), done: make(chan struct{})}
	pipeServers.Store(address.String(), listener)
	testDialContext = pipeDialContext
	server := &httptest.Server{Listener: listener, Config: &http.Server{Handler: handler}}
	server.StartTLS()
	return server
}

func (l *pipeListener) Accept() (net.Conn, error) {
	select {
	case conn := <-l.accept:
		return conn, nil
	case <-l.done:
		return nil, net.ErrClosed
	}
}

func (l *pipeListener) Close() error {
	l.once.Do(func() {
		pipeServers.Delete(l.address.String())
		close(l.done)
	})
	return nil
}

func (l *pipeListener) Addr() net.Addr { return l.address }

func pipeDialContext(ctx context.Context, _, address string) (net.Conn, error) {
	value, ok := pipeServers.Load(address)
	if !ok {
		return nil, &net.DNSError{Err: "test endpoint unavailable", Name: address}
	}
	client, server := net.Pipe()
	listener := value.(*pipeListener)
	select {
	case listener.accept <- server:
		return client, nil
	case <-listener.done:
		client.Close()
		server.Close()
		return nil, net.ErrClosed
	case <-ctx.Done():
		client.Close()
		server.Close()
		return nil, ctx.Err()
	}
}
