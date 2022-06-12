#!/bin/bash
echo pulling from git repo, you need internet.
git fetch && git rebase origin

echo restarting agent
./killagent.sh
