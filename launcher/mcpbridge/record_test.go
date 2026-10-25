package mcpbridge

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// records runs the input through a bridge that logs into a directory of its own, and returns
// today's lines decoded.
func records(t *testing.T, dir, input string) []map[string]any {
	t.Helper()
	b := bridge(t, dir)
	b.logs = t.TempDir()
	if err := b.Run(strings.NewReader(input), new(bytes.Buffer)); err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(filepath.Join(b.logs, "mcp-"+time.Now().UTC().Format(time.DateOnly)+".log"))
	if err != nil {
		t.Fatal(err)
	}
	var lines []map[string]any
	for _, l := range strings.Split(strings.TrimSpace(string(raw)), "\n") {
		var decoded map[string]any
		if err := json.Unmarshal([]byte(l), &decoded); err != nil {
			t.Fatalf("not one JSON object per line: %q", l)
		}
		lines = append(lines, decoded)
	}
	return lines
}

// Each answer's outcome, where it ran from the app's _meta, the whole request, and never the rows.
func TestRecordsOutcomeAndWhere(t *testing.T) {
	app := serve(t, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body := readBody(t, r)
		switch {
		case strings.Contains(body, `"id":1`):
			reply(t, w, `{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"secret row"}],"_meta":{"adminer-desktop/call":{"connection":"pgsql|db|app","connection_name":"Shop","database":"shop"}}}}`)
		case strings.Contains(body, `"id":2`):
			reply(t, w, `{"jsonrpc":"2.0","id":2,"result":{"content":[{"type":"text","text":"no such table"}],"isError":true}}`)
		case strings.Contains(body, `"id":3`):
			reply(t, w, `{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"{}"}],"_meta":{"adminer-desktop/call":{"refused":true}}}}`)
		default:
			reply(t, w, `{"jsonrpc":"2.0","id":4,"error":{"code":-32601,"message":"unknown method"}}`)
		}
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	input := `{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"execute_query","arguments":{"sql":"SELECT 1 < 2"}}}` + "\n" +
		`{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"describe_table"}}` + "\n" +
		`{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"execute_query"}}` + "\n" +
		`{"jsonrpc":"2.0","id":4,"method":"nope"}` + "\n"
	byID := map[float64]map[string]any{}
	for _, l := range records(t, dir, input) {
		byID[l["request"].(map[string]any)["id"].(float64)] = l
	}
	ok := byID[1]
	if ok["status"] != statusOK || ok["tool"] != "execute_query" || ok["connection_name"] != "Shop" || ok["database"] != "shop" || ok["error"] != nil {
		t.Errorf("an answered call reads %v", ok)
	}
	if strings.Contains(stringOf(t, ok), "secret row") {
		t.Error("the rows of an answer were written down")
	}
	if sql := ok["request"].(map[string]any)["params"].(map[string]any)["arguments"].(map[string]any)["sql"]; sql != "SELECT 1 < 2" {
		t.Errorf("the request was not kept whole: %v", sql)
	}
	if byID[2]["status"] != statusError || byID[2]["error"] != "no such table" {
		t.Errorf("a tool error reads %v", byID[2])
	}
	if byID[3]["status"] != statusRefused {
		t.Errorf("a refusal reads %v", byID[3])
	}
	if byID[4]["status"] != statusError || byID[4]["error"] != "unknown method" {
		t.Errorf("a JSON-RPC error reads %v", byID[4])
	}
}

// What never reaches the app is recorded too, with what the agent was told.
func TestRecordsWhatNeverReachedTheApp(t *testing.T) {
	lines := records(t, t.TempDir(), callTool()+"\n"+notification()+"\n")
	if len(lines) != 2 {
		t.Fatalf("%d lines for two messages", len(lines))
	}
	for _, l := range lines {
		if l["status"] != statusNotRunning {
			t.Errorf("with no app a message reads %v", l)
		}
	}
	if lines[0]["error"] == "" {
		t.Error("the sentence the agent got is not in the record")
	}
}

// The app's 204 to a notification is an answer, not a closed window.
func TestNotificationAnsweredWithSilenceIsOK(t *testing.T) {
	app := serve(t, http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	if l := records(t, dir, notification()+"\n")[0]; l["status"] != statusOK {
		t.Errorf("a notification reads %v", l)
	}
}

// A day per file, appended, private, and gone after keepDays.
func TestDayFilesRotateAndPrune(t *testing.T) {
	b := &Bridge{logs: t.TempDir()}
	day := time.Date(2026, 8, 3, 23, 59, 0, 0, time.UTC)
	old := filepath.Join(b.logs, "mcp-"+day.AddDate(0, 0, -20).Format(time.DateOnly)+".log")
	recent := filepath.Join(b.logs, "mcp-"+day.AddDate(0, 0, -5).Format(time.DateOnly)+".log")
	for _, f := range []string{old, recent} {
		if err := os.WriteFile(f, []byte("old\n"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	b.append(line{Method: "ping"}, day)
	b.append(line{Method: "ping"}, day)
	b.append(line{Method: "ping"}, day.Add(2*time.Minute))
	first, _ := os.ReadFile(filepath.Join(b.logs, "mcp-2026-08-03.log"))
	second, _ := os.ReadFile(filepath.Join(b.logs, "mcp-2026-08-04.log"))
	if bytes.Count(first, []byte("\n")) != 2 || bytes.Count(second, []byte("\n")) != 1 {
		t.Errorf("midnight split the lines %q / %q", first, second)
	}
	if _, err := os.Stat(old); !os.IsNotExist(err) {
		t.Error("a file past keepDays survived")
	}
	if _, err := os.Stat(recent); err != nil {
		t.Error("a file inside keepDays was pruned")
	}
	if info, err := os.Stat(filepath.Join(b.logs, "mcp-2026-08-04.log")); err != nil || info.Mode().Perm() != 0o600 {
		t.Errorf("the log is not private: %v", info.Mode())
	}
	(&Bridge{}).append(line{Method: "ping"}, day) // no directory: silence, not a crash
}

func stringOf(t *testing.T, v any) string {
	t.Helper()
	b, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

// A call still running when the bridge is stopped is recorded before it returns, not lost with
// the process.
func TestStoppedCallIsRecorded(t *testing.T) {
	asked := make(chan struct{})
	app := serve(t, http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		readBody(t, r) // or the server never notices the client leave
		close(asked)
		<-r.Context().Done()
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	b := bridge(t, dir)
	b.logs = t.TempDir()
	pr, pw := io.Pipe()
	defer closeOrFail(t, pw)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- b.RunUntil(ctx, pr, new(bytes.Buffer)) }()
	if _, err := io.WriteString(pw, callTool()+"\n"); err != nil {
		t.Fatal(err)
	}
	<-asked
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	raw, _ := os.ReadFile(filepath.Join(b.logs, "mcp-"+time.Now().UTC().Format(time.DateOnly)+".log"))
	if !strings.Contains(string(raw), `"status":"cancelled"`) {
		t.Errorf("the stopped call is not in the log: %q", raw)
	}
}
