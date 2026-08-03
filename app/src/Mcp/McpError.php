<?php
declare(strict_types=1);

namespace Desktop\Mcp;

/** A failure the agent should be told about in words rather than a stack trace.
*
* Anything thrown as one of these becomes an `isError` tool result: the model reads the message
* and can correct itself — a mistyped table name is a conversation, not a crash. Everything
* else that escapes is a bug in us, and is reported as a JSON-RPC internal error instead.
*/
class McpError extends \RuntimeException {
}
