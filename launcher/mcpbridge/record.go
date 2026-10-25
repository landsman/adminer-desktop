package mcpbridge

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// What became of a call. The bridge is the one place that sees every outcome: the four that end
// here never reach the app, and the app only says which of its answers were refusals.
const (
	statusOK             = "ok"
	statusError          = "error"           // the tool answered isError, or a JSON-RPC error
	statusRefused        = "refused"         // writes off or a Prod connection undid it, or the login reaches too far
	statusTimeout        = "timeout"         // the window went quiet for callTimeout
	statusWindowClosed   = "window closed"   // the app was there and is not, or answered nothing
	statusNotRunning     = "not running"     // no handshake: the app is closed or the feature off
	statusSessionExpired = "session expired" // the login page instead of an answer
	statusCancelled      = "cancelled"       // the client took the request back
)

// How long the day files are kept, as Desktop\Mcp\RequestLog kept them: enough for "what did it
// do while I was away for the weekend".
const keepDays = 14

type outcome struct {
	status string
	error  string // what the agent was told went wrong; never the rows of an answer
	where  where
}

// where is the _meta the app adds to an answer (Desktop\Mcp\Endpoint::where()): the connection
// and database the call ran on, which with a call free to name either is not the window's.
type where struct {
	Connection string `json:"connection,omitempty"`
	Name       string `json:"connection_name,omitempty"`
	Database   string `json:"database,omitempty"`
	Refused    bool   `json:"refused,omitempty"`
}

// answered reads the outcome out of the app's answer: only an error's text is kept, never the rows.
//
// Decoding a thousand rows to drop them took longer than the rest of the bridge's work on the call
// (make bench), so an answer with no error key skips it: quotes inside a JSON string are escaped,
// so `"error":` and `"isError":true` only match a real key, and _meta is read from where it starts.
func answered(body string) outcome {
	if !strings.Contains(body, `"error":`) && !strings.Contains(body, `"isError":true`) {
		o := outcome{status: statusOK}
		if i := strings.LastIndex(body, `"_meta":`); i >= 0 {
			var meta struct {
				Call where `json:"adminer-desktop/call"`
			}
			if json.NewDecoder(strings.NewReader(body[i+len(`"_meta":`):])).Decode(&meta) == nil {
				o.where = meta.Call
			}
		}
		if o.where.Refused {
			o.status = statusRefused
		}
		return o
	}
	var a struct {
		Error *struct {
			Message string `json:"message"`
		} `json:"error"`
		Result struct {
			IsError bool `json:"isError"`
			Content []struct {
				Text string `json:"text"`
			} `json:"content"`
			Meta struct {
				Call where `json:"adminer-desktop/call"`
			} `json:"_meta"`
		} `json:"result"`
	}
	if json.Unmarshal([]byte(body), &a) != nil {
		return outcome{status: statusOK} // a batch, or nothing we can read: it was answered
	}
	o := outcome{status: statusOK, where: a.Result.Meta.Call}
	switch {
	case a.Error != nil:
		o.status, o.error = statusError, a.Error.Message
	case a.Result.IsError:
		o.status = statusError
		for _, c := range a.Result.Content {
			o.error += c.Text
		}
	}
	if o.where.Refused {
		o.status = statusRefused
	}
	return o
}

// line is one call in the day's mcp-YYYY-MM-DD.log, a JSON object per line.
//
// The whole request, never the answer's rows: those are the database's contents, and a plaintext
// copy beside it would be a worse leak than what is being audited.
type line struct {
	Time     string `json:"time"`
	Status   string `json:"status"`
	Duration int64  `json:"duration_ms"`
	Method   string `json:"method"`
	Tool     string `json:"tool,omitempty"`
	where
	Error   string `json:"error,omitempty"`
	Request any    `json:"request,omitempty"`
}

func (b *Bridge) record(m rpcMessage, request string, start time.Time, o outcome) {
	var raw any = request // a line that is not JSON is kept as the text it was
	if json.Valid([]byte(request)) {
		raw = json.RawMessage(request)
	}
	b.append(line{
		Time:     start.UTC().Format("2006-01-02T15:04:05.000Z"),
		Status:   o.status,
		Duration: time.Since(start).Milliseconds(),
		Method:   m.method,
		Tool:     m.tool,
		where:    o.where,
		Error:    o.error,
		Request:  raw,
	}, start)
}

// LogExit says in the day's file why the bridge stopped: clients log nothing when a server goes
// away, so a closed stdin, a kill and a crash otherwise all look like a server that is gone.
func LogExit(logs, why string) {
	(&Bridge{logs: logs}).append(line{
		Time:   time.Now().UTC().Format("2006-01-02T15:04:05.000Z"),
		Status: statusOK,
		Method: "bridge-exit",
		Error:  why,
	}, time.Now())
}

// append writes one line with one O_APPEND write, which several bridges and concurrent calls
// cannot interleave, and prunes what is past keepDays once a day. Rotation is the filename: a new
// day names a new file, and yesterday's is left exactly as it was.
func (b *Bridge) append(l line, at time.Time) {
	if b.logs == "" {
		return
	}
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false) // SQL reads `a < b`, not `a < b`
	if enc.Encode(l) != nil {
		return
	}
	path := filepath.Join(b.logs, "mcp-"+at.UTC().Format(time.DateOnly)+".log")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600) // it names tables and queries
	if err != nil {
		return
	}
	f.Write(buf.Bytes()) //nolint:errcheck // a record that cannot be written must not fail the call
	f.Close()            //nolint:errcheck
	if day := at.UTC().Format(time.DateOnly); b.pruned.Swap(day) != day {
		prune(b.logs, at)
	}
}

var dayFile = regexp.MustCompile(`^mcp-(\d{4}-\d{2}-\d{2})\.log$`)

// prune goes by the date in the name rather than mtime: a file copied or restored keeps its
// meaning rather than being judged by when it landed.
func prune(dir string, now time.Time) {
	cutoff := now.UTC().AddDate(0, 0, -keepDays).Format(time.DateOnly)
	entries, _ := os.ReadDir(dir)
	for _, e := range entries {
		if m := dayFile.FindStringSubmatch(e.Name()); m != nil && m[1] < cutoff {
			os.Remove(filepath.Join(dir, e.Name())) //nolint:errcheck
		}
	}
}
