"""
apt's own answers to what the reporter works out itself, for RealServerTest:
how many installed packages have a newer version in the release's security
pocket (from Ubuntu's archive), and how apt orders the versions it knows.
Run with the system Python, which has python3-apt:

    /usr/bin/python3 apt_security_updates.py [--status FILE] [--write-older FILE]

--status reads that package list instead of dpkg's. --write-older writes a
copy of dpkg's list with some up-to-date packages set back to an older
version apt knows, so the count has something to find.

Prints one line of JSON: {"count": n, "pairs": [[a, b, order], ...], "madeOlder": n}.
"""

import json
import sys

import apt.progress.base
import apt_pkg

arguments = dict(zip(sys.argv[1::2], sys.argv[2::2]))

apt_pkg.init_config()
if "--status" in arguments:
    apt_pkg.config.set("Dir::State::status", arguments["--status"])
apt_pkg.init_system()
cache = apt_pkg.Cache(apt.progress.base.OpProgress())

release = dict(
    line.strip().split("=", 1)
    for line in open("/etc/os-release")
    if "=" in line
)
pocket = release["VERSION_CODENAME"].strip("\"'") + "-security"


def from_security(version):
    return any(
        found.origin == "Ubuntu" and found.archive == pocket
        for found, _ in version.file_list
    )


count = 0
pairs = []
older = {}
for package in cache.packages:
    versions = [version.ver_str for version in package.version_list]
    for i, a in enumerate(versions):
        for b in versions[i + 1 :]:
            if len(pairs) < 100000:
                pairs.append([a, b, apt_pkg.version_compare(a, b)])

    installed = package.current_ver
    if package.current_state != apt_pkg.CURSTATE_INSTALLED or installed is None:
        continue

    security = [v for v in package.version_list if from_security(v)]
    if any(apt_pkg.version_compare(v.ver_str, installed.ver_str) > 0 for v in security):
        count += 1
    elif security:
        # Up to date: an older version apt knows that the security pocket's is newer than.
        before = [
            v.ver_str
            for v in package.version_list
            if all(apt_pkg.version_compare(v.ver_str, s.ver_str) < 0 for s in security)
        ]
        if before:
            older[package.name] = before[0]

made_older = 0
if "--write-older" in arguments:
    entries = open(apt_pkg.config.find_file("Dir::State::status")).read().split("\n\n")
    names = [entry.split("\n", 1)[0].removeprefix("Package: ") for entry in entries]
    # Only names dpkg lists once, so an architecture can't be mixed up; every other one.
    chosen = [name for name in older if names.count(name) == 1][::2]
    rewritten = []
    for entry in entries:
        name = entry.split("\n", 1)[0].removeprefix("Package: ")
        if name in chosen:
            lines = entry.split("\n")
            entry = "\n".join(
                "Version: " + older[name] if line.startswith("Version: ") else line
                for line in lines
            )
            made_older += 1
        rewritten.append(entry)
    open(arguments["--write-older"], "w").write("\n\n".join(rewritten))

print(json.dumps({"count": count, "pairs": pairs, "madeOlder": made_older}))
