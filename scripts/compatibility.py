"""Plan and verify Nextcloud compatibility using the official release schedule."""
import argparse
import json
from pathlib import Path
import re
import tarfile
import xml.etree.ElementTree as ET

INFO = Path("appinfo/info.xml")
VERSION = r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"


def metadata(xml):
    root = ET.fromstring(xml)
    version = root.findtext("version", "")
    nc, = root.findall("dependencies/nextcloud")
    low, high = (nc.attrib[key] for key in ("min-version", "max-version"))
    if not (root.findtext("id") == "calenai" and re.fullmatch(VERSION, version)
            and all(re.fullmatch(r"[1-9][0-9]*", v) for v in (low, high))
            and int(low) <= int(high)):
        raise ValueError("Invalid CalenAI release metadata")
    return version, int(low), int(high)


def versions(schedule, heading, column):
    section = re.search(rf"^## {heading}[ \t]*\n(.*?)(?=^## |\Z)", schedule, re.M | re.S)
    if not section:
        raise ValueError(f"Missing schedule section {heading}")
    table = re.search(r"^\| *Version code *\|.*(?:\n\|[^\n]*)+", section[1], re.M)
    if not table:
        raise ValueError(f"Missing version table in {heading}")
    header, separator, *rows = table[0].splitlines()

    def cells(row):
        return [cell.strip().replace("**", "") for cell in row.strip("|").split("|")]

    index = cells(header).index(column)
    result = {}
    for row in rows:
        values = cells(row)
        major = int(values[0])
        match = re.fullmatch(rf"({VERSION})(?:\s+\([^()]+\))?", values[index])
        if not match or int(match[1].split(".")[0]) != major or major in result:
            raise ValueError(f"Invalid {column} for Nextcloud {major}: {row}")
        result[major] = match[1]
    if not result:
        raise ValueError(f"No stable releases in {heading}")
    return result


def plan(schedule, current):
    version, low, high = current
    supported = versions(schedule, "Currently supported versions", "Current version")
    minimum, maximum = min(low, min(supported)), max(high, max(supported))
    required = range(minimum, maximum + 1)
    releases = dict(supported)
    if set(required) - releases.keys():
        releases = versions(schedule, "EOL Versions", "Last version") | supported
    missing = set(required) - releases.keys()
    if missing:
        raise ValueError(f"No stable release for intervening majors {sorted(missing)}")
    next_version = version
    if (minimum, maximum) != (low, high):
        prefix, patch = version.rsplit(".", 1)
        next_version = f"{prefix}.{int(patch) + 1}"
    return dict(version=version, low=low, high=high, minimum=minimum, maximum=maximum,
                next_version=next_version, matrix=[releases[m] for m in required])


def update(path, version, low, high):
    # Preserve the description's CDATA and formatting outside the two edited elements.
    xml = path.read_text()
    metadata(xml)
    xml, count = re.subn(r"<version>[^<]*</version>", f"<version>{version}</version>", xml)
    if count != 1:
        raise ValueError("Expected one app version")
    xml, count = re.subn(r"<nextcloud\b[^>]*/>", f'<nextcloud min-version="{low}" max-version="{high}"/>', xml)
    if count != 1 or metadata(xml) != (version, low, high):
        raise ValueError("Failed to update Nextcloud dependency")
    path.write_text(xml)


def verify(archive, version, low, high):
    if archive.name != f"calenai-{version}.tar.gz":
        raise ValueError("Unexpected archive name")
    with tarfile.open(archive, "r:gz") as package:
        info, = [m for m in package if m.name == "calenai/appinfo/info.xml"]
        if not info.isfile() or metadata(package.extractfile(info).read()) != (version, low, high):
            raise ValueError("Packaged app metadata differs from the tested release")


def main():
    parser = argparse.ArgumentParser()
    commands = parser.add_subparsers(dest="command", required=True)
    discover = commands.add_parser("plan")
    discover.add_argument("schedule", type=Path)
    discover.add_argument("--base-info", type=Path)
    for name in ("update", "verify"):
        cmd = commands.add_parser(name)
        cmd.add_argument("path", type=Path)
        cmd.add_argument("version")
        cmd.add_argument("low", type=int)
        cmd.add_argument("high", type=int)
    args = parser.parse_args()
    if args.command == "plan":
        current = metadata(INFO.read_bytes())
        if args.base_info:
            previous = metadata(args.base_info.read_bytes())
            if current[1:] != previous[1:] and (
                tuple(map(int, current[0].split("."))) <= tuple(map(int, previous[0].split(".")))
            ):
                raise ValueError("Changing the supported range requires a higher app version")
        print(json.dumps(plan(args.schedule.read_text(), current)))
    elif args.command == "update":
        update(args.path, args.version, args.low, args.high)
    else:
        verify(args.path, args.version, args.low, args.high)


if __name__ == "__main__":
    main()
