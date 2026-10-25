package mcpbridge

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"
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
					_, result := bridge.exchange(context.Background(), request)
					bridge.record(message, request, start, result)
				}
			})
		}
	}
}

// answerOf is a tool result of n rows with the app's _meta, as execute_query answers.
func answerOf(n int) string {
	rows := make([]string, n)
	for i := range rows {
		rows[i] = fmt.Sprintf(`{"id":%d,"email":"user%d@example.com","total":"%d.50","created_at":"2026-10-25 12:00:00"}`, i, i, i)
	}
	text := strings.ReplaceAll("["+strings.Join(rows, ",")+"]", `"`, `\"`)
	return `{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"` + text + `"}],"_meta":{"adminer-desktop/call":{"connection":"pgsql|db|app","connection_name":"Shop","database":"shop"}}}}`
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
