--TEST--
Gracefully handle out-of-sync spans from traced function [user]
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
--FILE--
<?php

// Since dd_trace_serialize_closed_spans() destroys the open span stack,
// when this closure runs, DDTrace\SpanData will have been freed already.
DDTrace\trace_function('shutdown_and_flush', function (DDTrace\SpanData $span) {
    echo 'You should not see this.' . PHP_EOL;
    $span->name = 'shutdown_and_flush';
});

function shutdown_and_flush() {
    var_dump(dd_trace_serialize_closed_spans());
}
shutdown_and_flush();

echo 'Done.' . PHP_EOL;
?>
--EXPECT--
array(0) {
}
[ddtrace] [error] Cannot run tracing closure for shutdown_and_flush(); spans out of sync; This message is only displayed once. Specify DD_TRACE_ONCE_LOGS=0 to show all messages.
Done.
[ddtrace] [info] No finished traces to be sent to the agent
