// Command mcpload is several agents at once against one running app, for checking by hand that
// the bridge keeps answers apart and a ping free while every agent has a queue of calls.
//
//	go run ./tests/e2e/harness/mcpload -bridge build.noindex/adminer-desktop -agents 5 -calls 10 -seconds 2
//
// Each agent is its own `-mcp` process, as each agent session spawns one. Every call is
// `select <n> as n, pg_sleep(<s>)` with n unique across all agents, so an answer that reached
// the wrong id or the wrong agent shows as a mismatch rather than passing. Behind each agent's
// queue goes one ping, timed: how long a liveness check waits while that agent is busy.
//
// Postgres only (pg_sleep). It talks to whichever app the bridge finds, so point HOME at an
// isolated one (.docs/qa/mcp-bridge.md) rather than at the app holding your real connections.
package main

import (
	"bufio"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"sort"
	"strconv"
	"sync"
	"time"
)

func main() {
	bridge := flag.String("bridge", "build.noindex/adminer-desktop", "the launcher to run with -mcp")
	agents := flag.Int("agents", 3, "bridges running at once")
	calls := flag.Int("calls", 10, "calls each agent queues")
	seconds := flag.Float64("seconds", 2, "pg_sleep in every call")
	flag.Parse()

	var (
		mu      sync.Mutex
		results []result
		wg      sync.WaitGroup
	)
	start := time.Now()
	for a := range *agents {
		wg.Add(1)
		go func() {
			defer wg.Done()
			got, err := agent(*bridge, a, *calls, *seconds, start)
			if err != nil {
				log.Printf("agent %d: %v", a, err)
			}
			mu.Lock()
			results = append(results, got...)
			mu.Unlock()
		}()
	}
	wg.Wait()
	report(results, *agents, *calls, *seconds, time.Since(start))
}

type result struct {
	agent  int
	ping   bool
	after  time.Duration
	status string // "ok", or what went wrong
}

func agent(bridge string, a, calls int, seconds float64, start time.Time) ([]result, error) {
	cmd := exec.Command(bridge, "-mcp")
	in, err := cmd.StdinPipe()
	if err != nil {
		return nil, err
	}
	out, err := cmd.StdoutPipe()
	if err != nil {
		return nil, err
	}
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	for c := range calls {
		n := a*1000 + c + 1
		sql := fmt.Sprintf("select %d as n, pg_sleep(%g)", n, seconds)
		if err := send(in, map[string]any{"jsonrpc": "2.0", "id": n, "method": "tools/call",
			"params": map[string]any{"name": "execute_query", "arguments": map[string]any{"sql": sql}}}); err != nil {
			return nil, err
		}
	}
	if err := send(in, map[string]any{"jsonrpc": "2.0", "id": "ping-" + strconv.Itoa(a), "method": "ping"}); err != nil {
		return nil, err
	}
	if err := in.Close(); err != nil { // the bridge answers everything in flight before it exits
		return nil, err
	}
	var got []result
	lines := bufio.NewScanner(out)
	lines.Buffer(make([]byte, 1<<20), 1<<20)
	for lines.Scan() {
		got = append(got, judge(a, lines.Bytes(), time.Since(start)))
	}
	return got, cmd.Wait()
}

func send(w io.Writer, message map[string]any) error {
	line, err := json.Marshal(message)
	if err != nil {
		return err
	}
	_, err = w.Write(append(line, '\n'))
	return err
}

// judge says whether one answer is the one its id asked for.
func judge(a int, line []byte, after time.Duration) result {
	var answer struct {
		ID     json.RawMessage `json:"id"`
		Result struct {
			IsError bool `json:"isError"`
			Content []struct {
				Text string `json:"text"`
			} `json:"content"`
		} `json:"result"`
		Error *struct {
			Message string `json:"message"`
		} `json:"error"`
	}
	if err := json.Unmarshal(line, &answer); err != nil {
		return result{agent: a, after: after, status: "not JSON: " + string(line)}
	}
	var asked int
	if json.Unmarshal(answer.ID, &asked) != nil {
		return result{agent: a, ping: true, after: after, status: "ok"}
	}
	switch {
	case answer.Error != nil:
		return result{agent: a, after: after, status: fmt.Sprintf("id %d: rpc error %s", asked, answer.Error.Message)}
	case answer.Result.IsError || len(answer.Result.Content) == 0:
		text := ""
		if len(answer.Result.Content) > 0 {
			text = answer.Result.Content[0].Text
		}
		return result{agent: a, after: after, status: fmt.Sprintf("id %d: tool error %.120s", asked, text)}
	}
	var rows struct {
		Rows []struct {
			N json.Number `json:"n"`
		} `json:"rows"`
	}
	if json.Unmarshal([]byte(answer.Result.Content[0].Text), &rows) != nil || len(rows.Rows) != 1 {
		return result{agent: a, after: after, status: fmt.Sprintf("id %d: no row", asked)}
	}
	if string(rows.Rows[0].N) != strconv.Itoa(asked) {
		return result{agent: a, after: after, status: fmt.Sprintf("MISMATCH: id %d got n=%s", asked, rows.Rows[0].N)}
	}
	return result{agent: a, after: after, status: "ok"}
}

func report(results []result, agents, calls int, seconds float64, wall time.Duration) {
	var answered, pinged, bad int
	var pings []float64
	var last time.Duration
	for _, r := range results {
		if r.status != "ok" {
			bad++
			fmt.Printf("  agent %d: %s\n", r.agent, r.status)
		}
		if r.ping {
			pinged++
			pings = append(pings, r.after.Seconds())
			continue
		}
		answered++
		last = max(last, r.after)
	}
	sort.Float64s(pings)
	fmt.Printf("%d agents x %d calls of %gs: %d/%d answered, %d/%d pings, %d not ok, wall %.1fs\n",
		agents, calls, seconds, answered, agents*calls, pinged, agents, bad, wall.Seconds())
	fmt.Printf("pings answered after (s): %.1f; last call after %.1fs\n", pings, last.Seconds())
	if bad > 0 || answered != agents*calls || pinged != agents {
		os.Exit(1)
	}
}
