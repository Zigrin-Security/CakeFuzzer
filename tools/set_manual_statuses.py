#!/usr/bin/env python

import argparse
import json


def main():
    args = parse_arguments()

    with open(args.results, "r") as fp:
        vulns = json.load(fp)
    vulns = set_statuses(vulns, args.field, args.contains, args.status)

    if args.write:
        with open(args.results, "w") as fp:
            json.dump(vulns, fp, indent=4)
    else:
        print(json.dumps(vulns, indent=4))


def set_statuses(vulns, field_name, contains_vals, status):
    new_vulns = []
    for vuln in vulns:
        if "status" in vuln:
            new_vulns.append(vuln)
            continue
        for contain in contains_vals:
            if field_name in vuln:
                if (
                    isinstance(vuln[field_name], (str, list, tuple))
                    and contain in vuln[field_name]
                ):
                    vuln["status"] = status
                    break
                elif (
                    isinstance(vuln[field_name], int)
                    and int(contain) == vuln[field_name]
                ):
                    vuln["status"] = status
                    break
                elif contain == vuln[field_name]:
                    vuln["status"] = status
                    break
        new_vulns.append(vuln)

    return new_vulns


def parse_arguments():
    parser = argparse.ArgumentParser(
        description="Script to set statuses of vulnerabilities in results.json"
    )
    parser.add_argument(
        "-r",
        "--results",
        help="Path to results.json file",
        required=True,
    )
    parser.add_argument(
        "-f",
        "--field",
        help="Field name. If the field is type int, -c param has to be equal to its value",
        required=True,
        type=str,
    )
    parser.add_argument(
        "-c",
        "--contains",
        help="If the field contains any of the provided values the status will be assigned",
        required=True,
        nargs="+",
    )
    parser.add_argument(
        "-s",
        "--status",
        help="Status to set for all maching vulnerabilities",
        required=True,
        type=str,
    )
    parser.add_argument(
        "-w", "--write", help="Write changes to the results file", action="store_true"
    )
    args = parser.parse_args()
    return args


if __name__ == "__main__":
    main()
