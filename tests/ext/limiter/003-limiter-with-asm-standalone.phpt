--TEST--
rate limiter always allow when DD_EXPERIMENTAL_APPSEC_STANDALONE_ENABLED enabled
--SKIPIF--
<?php if (getenv('USE_ZEND_ALLOC') === '0') die('skip timing sensitive test, does not make sense with valgrind'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_RATE_LIMIT=10
DD_TRACE_SAMPLE_RATE=1
DD_EXPERIMENTAL_APPSEC_STANDALONE_ENABLED=1
--FILE--
<?php
$spans = [];
$sampled = 0;
$loopBreak = 1000;

while (true) {
    \DDTrace\start_span();
    \DDTrace\close_span();

    $spans = array_merge($spans, \dd_trace_serialize_closed_spans());
    $sampled = 0;

    foreach ($spans as $span) {
        if (isset($span["metrics"]["_sampling_priority_v1"])) {
            $sampled++;
        }
    }

    if ($sampled > 20) {
        break;
    }

    if (--$loopBreak < 0) {
        echo "No 20 spans were sampled.\n";
        break; # avoid infinite loop with DD_TRACE_ENABLED=0
    }
}

var_dump(count($spans));
?>
--EXPECT--
int(21)
