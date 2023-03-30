#!/usr/bin/env python

import argparse
import json

GROUPS = {
    "strategy_name": "Strategy Name",
    "method": "Method",
    "status": "Status",
    "vulnerability_location": "Location",
    "path": "Path",
}


def main():
    args = parse_arguments()
    vulns = json.loads(args.results.read())
    stats = {}
    stats["total"] = len(vulns)
    stats["empty"] = 0
    stats["grouped"] = {}
    groups = prepare_groups(stats, args.groups)

    for vuln in vulns:
        if not vuln:
            stats["empty"] += 1
            continue

        calculate_vuln_stats(vuln, groups, stats)

    sort_stats(stats)

    print_stats(stats, "raw")


def prepare_groups(stats, requested_groups):
    """Populate stats to measure with requested groups"""

    global GROUPS
    groups = {}
    for group in GROUPS:
        if group in requested_groups:
            groups[group] = GROUPS[group]

    for group in groups:
        stats["grouped"][groups[group]] = {}

    return groups


# I'm sure I'll soon forget what this function is doing. Needs to be documented.
def calculate_vuln_stats(vuln, groups, stats):
    for group in groups:
        if group not in vuln:
            if "<Empty>" in stats["grouped"][groups[group]]:
                stats["grouped"][groups[group]]["<Empty>"] += 1
            else:
                stats["grouped"][groups[group]]["<Empty>"] = 1
            continue
        elif vuln[group] is None:
            stats["empty"] += 1
            break

        # Special grouping for a few groups. We want to count sum the vulnerable param names

        # From the below example vulnerability_location we want to group by _POST: value
        # Rest is not important for stats
        #
        # {
        #   "_POST": {
        #     "value": {
        #       "1`'\"~!@#$%^&*()+__cakefuzzer_sqli_01794477265441934112__": "1`'\"~!@#$%^&*()+__cakefuzzer_sqli_01669698090633161232__"
        #     }
        #   }
        # }

        if group == "vulnerability_location":
            if not vuln[group]:
                vuln_category = "<Empty>"
            for item in vuln[group]:
                if isinstance(vuln[group], str):
                    vuln_category = vuln[group]
                elif isinstance(vuln[group], dict):
                    first = list(vuln[group].keys())[0]
                    if isinstance(vuln[group][first], str):
                        second = vuln[group][first]
                    elif isinstance(vuln[group][first], dict):
                        second = list(vuln[group][first].keys())[0]
                    if first == "path":
                        second = "/".join(second.split("/")[:2])
                    vuln_category = f"{first}:{second}"
                break
        elif group == "path":
            vuln_category = "/".join(vuln[group].split("/")[:2])
        else:
            vuln_category = vuln[group]

        if vuln_category in stats["grouped"][groups[group]]:
            stats["grouped"][groups[group]][vuln_category] += 1
        else:
            stats["grouped"][groups[group]][vuln_category] = 1


# TODO:
def sort_stats(stats):
    pass


def print_stats(stats, print_format="json"):
    if print_format == "json":
        print(json.dumps(stats))
        return

    # Assume raw format
    print(f"Total vulnerabilities: {stats['total']}")
    print(f"Empty vulnerabilities: {stats['empty']}")
    if stats["grouped"]:
        print("Grouped:")
        for group in stats["grouped"]:
            print(f"\t{group}")
            for subgroup in stats["grouped"][group]:
                print(f"\t\t{subgroup}: {stats['grouped'][group][subgroup]}")


def parse_arguments():
    global GROUPS
    parser = argparse.ArgumentParser(description="Return statistics about scan results")
    parser.add_argument(
        "-r",
        "--results",
        help="Results.json file",
        required=True,
        type=argparse.FileType("r"),
    )
    parser.add_argument(
        "-g",
        "--groups",
        help=f"Groups for more detailed stats. Available groups: {', '.join(GROUPS.keys())}",
        nargs="+",
        default=list(GROUPS.keys()),
        required=False,
    )
    args = parser.parse_args()
    return args


if __name__ == "__main__":
    main()
