#!/bin/bash
kill `ps auxwf|awk '/usr\/bin\/php [a]gent.php/ {print $2}'|tail -1`
