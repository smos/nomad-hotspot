#!/bin/bash
kill `ps auxwf|awk '/\/usr\/bin\/php \/home\/pi\/nomad-hotspot\/agent.php/|tail -1`
