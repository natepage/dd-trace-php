--TEST--
test caching that calls are not traced at first works with opcache's preload script
--INI--
opcache.enable_cli=1
--FILE--
<?php

if (!extension_loaded('Zend OPcache')) die("opcache is required for this test\n");

require __DIR__ . '/include.php';

var_dump(opcache_is_script_cached(__DIR__ . '/include.php'));

// call the functions without tracing them to prime the cache
Datadog\NegativeClass::negativeMethod();
Datadog\negative_function();

// Add instrumentation calls
\DDTrace\trace_method('datadog\\negativeclass', 'negativemethod', function () {
    echo "NegativeClass::negative_method\n";
});
\DDTrace\trace_function('datadog\\negative_function', function () {
    echo "negative_function\n";
});

// call again
Datadog\NegativeClass::negativeMethod();
Datadog\negative_function();

echo "Done.";
?>
--EXPECT--
bool(true)
Executed negativeMethod
Executed negative_function
Executed negativeMethod
NegativeClass::negative_method
Executed negative_function
negative_function
Done.
