#!/bin/bash
kill `ps auxwf|awk "/\/usr\/bin\/php \/home\/$LOGNAME\/nomad-hotspot\/agent.php/ {print $2}"|tail -1|awk '{print $2}'`
