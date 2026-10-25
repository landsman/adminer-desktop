package mcpbridge

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"testing"
	"time"
)

// What recording adds to a call: the same call against a stub app, with the record off and on.
// The stub answers at once, so this is the bridge's own cost — a real call adds PHP and the
// database on top. go test -run '^$' -bench . ./launcher/mcpbridge/
func BenchmarkCall(b *testing.B) {
	for _, rows := range []int{10, 1000} {
		answer := answerOf(rows)
		for _, logs := range []bool{false, true} {
			b.Run(fmt.Sprintf("rows=%d/record=%v", rows, logs), func(b *testing.B) {
				app := serve(b, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
					readBody(b, r)
					reply(b, w, answer)
				}))
				defer app.Close()
				dir := b.TempDir()
				handshake(b, dir, app.URL+"/adminer.php?pgsql=x")
				bridge := NewIn(dir)
				if logs {
					bridge.logs = fullLogDir(b)
				}
				request := `{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"execute_query","arguments":{"sql":"SELECT * FROM orders WHERE created_at > now() - interval '1 day'"}}}`
				message := parse(request)
				for b.Loop() {
					start := time.Now()
					answer, result := bridge.exchange(context.Background(), request)
					if answer == nil || result.status != statusOK || result.where.Database != "shop" {
						b.Fatalf("the call did not go through, so its time says nothing: %+v", result)
					}
					bridge.record(message, request, start, result)
				}
			})
		}
	}
}

// answerOf is a tool result of n rows as Server.php writes it: the rows pretty-printed into the
// text, the envelope compact, and the app's _meta.
func answerOf(n int) string {
	rows := make([]map[string]any, n)
	for i := range rows {
		rows[i] = map[string]any{"id": i, "email": fmt.Sprintf("user%d@example.com", i), "total": fmt.Sprintf("%d.50", i), "created_at": "2026-10-25 12:00:00"}
	}
	text, _ := json.MarshalIndent(map[string]any{"rows": rows}, "", "    ")
	content, _ := json.Marshal([]map[string]string{{"type": "text", "text": string(text)}})
	// By hand rather than from a map, which Go sorts: Server.php puts _meta last.
	return `{"jsonrpc":"2.0","id":3,"result":{"content":` + string(content) +
		`,"_meta":{"adminer-desktop/call":{"connection":"pgsql|db|app","connection_name":"Shop","database":"shop"}}}}`
}

// fullLogDir is a log directory as it stands after two weeks: a day file each, and the app's own
// log beside them, so prune() reads a real-sized directory on every append.
func fullLogDir(b *testing.B) string {
	dir := b.TempDir()
	names := []string{"adminer-desktop.log", "api.log"}
	for d := range keepDays {
		names = append(names, "mcp-"+time.Now().UTC().AddDate(0, 0, -d-1).Format(time.DateOnly)+".log")
	}
	for _, n := range names {
		if err := os.WriteFile(filepath.Join(dir, n), []byte("x\n"), 0o600); err != nil {
			b.Fatal(err)
		}
	}
	return dir
}
