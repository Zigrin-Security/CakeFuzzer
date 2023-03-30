import re
from typing import Dict, List, Match, Optional, Union


class RouteComputer:
    """
    Computational class that serves simple API for generating routes,
        either from a single regexp or a whole set.
    """

    @staticmethod
    def _drop_regex_syntax(match_obj: Match) -> str:  # type: ignore
        r"""
        Helper method for initial parsing in re.sub.
        regexp breakdown:
             (\(\?:)([\/]?)(\(\?P<)([^>]*)(>)(([^|)]*\|?)*)(\){2})(\??)|(#\^)|(\[\/]\*\$#)|(\/\*\$#)
        group no 1     2       3      4    5      6(7)        8     9     10      11           12

        Example input: "#^/admin/(?:(?P<controller>[^/]+))[/]*$#"
        this method is called for every match in the regexp above. There are 4 cases:
        Groups 1-9 match "(?:(?P<controller>[^/]+))" (and any other possible named groups in the input).
        Group 10 matches start of the input: "#^".
        Group 11 matches the end of the input: "[/]*$#".
        Group 12 matches only for single input, "#^/*$#", which is kind of a special case.

        In detail,
        Groups 1, 3, 5, 7, 8 are replaced with "".
        Group 2 matches an optional "/" and passes that to output.
        Group 4 matches named group name "controller" and replaces it with a marked group name.
                ( direct match: controller, after marking: ~controller~ ).
        Group 6 matches the contents of named group, from input: "[^/]+". In case of group named plugin,
                contents of the group will be something like "plugin_1|plugin_2". If so, we leave them in the output,
                marking them accordingly ( ~plugin_1|plugin_2~ ).
        Group 9 matches "?" after named group, not present in the example. Adds "optional~" mark to the group name.
        #^/admin(?:/(?P<plugin>aad_auth|apcu_cache|assets|cake_resque|cert_auth|lin_o_t_p_auth|magic_tools|oidc_auth|shibb_auth|sys_log|sys_log_logable|url_cache))/(?:(?P<controller>[^/]+))/(?:(?P<action>[^/]+))(?:/(?P<_args_>.*))?[/]*$#
        /admin/~aad_auth|apcu_cache|assets|cake_resque~/~controller~/~action~/~args~optional~/
        """  # noqa: E501
        path = ""
        if match_obj.group(2) is not None:
            # match optional "/"
            path += match_obj.group(2)

        if (
            match_obj.group(6) is not None
            and "|" in match_obj.group(6)
            and "{" not in match_obj.group(6)
        ):
            # preserve list of possible elements and not include hex regexes.txt here
            path += "~" + match_obj.group(6) + "~"
        elif match_obj.group(4) is not None:
            # place name of parameterizable part of path
            path += "~" + str(match_obj.group(4)).strip("_") + "~"

        if match_obj.group(9) == "?":
            # mark optional capture group accordingly
            path += "optional~"

        if match_obj.group(11) is not None or match_obj.group(12) is not None:
            # preserve trailing "/"
            path += "/"

        return path

    @staticmethod
    def _processing_finished(path: List[str]) -> bool:
        """
        Return True if there is any unfinished path in paths.
        """
        return not bool([True for each in path if "~" in each])

    def _remove_impossible_paths(
        self, lines: List[str], options: Dict[str, List[str]]
    ) -> List[str]:
        paths = []
        for line in lines:
            if self._is_path_possible(line, options):
                paths.append(line)
        return paths

    def _is_path_possible(self, line: str, options: Dict[str, List[str]]) -> bool:
        """
        Check if the path is correct for any existing controller -> action -> required
        arguments. This method should be used only for /Controller/Action/Args routes.
        """

        # Skip paths without explicitly set actions
        # Those are covered by routes with explicit actions
        if len(line.split("/")) < 3:
            return False

        path_info = self._extract_path_info(line)
        all_controllers = options["controllers"]

        # Accept fuzzable controller
        if "_CAKE_FUZZER_FUZZABLE_" in path_info["controller"]:
            return True

        # Skip paths with non existing controller
        if path_info["controller"] not in all_controllers.keys():
            return False

        # Accept fuzzable action for known controller
        if "_CAKE_FUZZER_FUZZABLE_" in path_info["action"]:
            return True

        # Skip paths with non existing action for a controller
        if path_info["action"] not in all_controllers[path_info["controller"]]:
            return False

        # Skip paths with not enough required arguments
        if len(path_info["arguments"]) < self._get_number_of_required_arguments(
            all_controllers[path_info["controller"]][path_info["action"]]
        ):
            return False

        return True

    def _get_number_of_required_arguments(self, arguments: List[Dict]):
        num = 0
        for arg in arguments:
            if not arg["optional"]:
                num += 1
            else:
                # No required parameters can exist if previous param was optional
                break

        return num

    def _extract_path_info(self, line: str):
        """
        Return dict with information about controller, action and arguments.
        This method should be used only for /Controller/Action/Args routes.
        """
        components = {}
        line = line.split("/")
        line.pop(0)
        components["controller"] = line.pop(0)
        components["action"] = line.pop(0)
        components["arguments"] = list(filter(None, line))

        return components

    @staticmethod
    def _expand_dynamic_paths(
        path: str,
        options: Dict[str, Union[List[str], Dict[str, List[str]]]],
    ) -> List[str]:
        """
        Recursively expand given dynamic path into all possible permutations.
        /admin/~aad_auth|apcu_cache|assets|cake_resque~/~controller~/~action~/~args~optional~/
        """
        paths: List[str] = []
        split_path = path.split("/")
        loc_path = split_path.copy()
        if RouteComputer._processing_finished(loc_path):
            return ["/".join(loc_path)]

        for index, section in enumerate(loc_path):
            if section.startswith("~"):
                if section.endswith("~optional~"):
                    loc_path[index] = section.rstrip("~optional~")
                    final_path = loc_path[:index] + [""]
                    paths += RouteComputer._expand_dynamic_paths(
                        "/".join(final_path), options
                    )

                    paths += RouteComputer._expand_dynamic_paths(
                        "/".join(loc_path), options
                    )

                else:
                    section = section.strip("~")
                    if "|" in section:
                        for key in section.split("|"):
                            loc_path[index] = key
                            paths += RouteComputer._expand_dynamic_paths(
                                "/".join(loc_path), options
                            )
                    elif section in options:
                        for option in options[section]:
                            if (
                                section == "controller"
                                and len(loc_path) > index
                                and loc_path[index + 1] == "~action~"
                            ):
                                next_options = {
                                    key: value if not key == "action" else value[option]
                                    for key, value in options.items()
                                }
                                # this allows for passing only those actions, that match controller  # noqa: E501

                            else:
                                next_options = options
                            loc_path[index] = option
                            paths += RouteComputer._expand_dynamic_paths(
                                "/".join(loc_path), next_options
                            )
                    elif section not in options:
                        loc_path[index] = section
                        # as per discussion in https://github.com/Zigrin-Security/CakeFuzzer/pull/14
                        # routes, that have unknown dynamic segments are omitted for now
                        if "_CAKE_FUZZER_" in section:
                            paths += RouteComputer._expand_dynamic_paths(
                                "/".join(loc_path), options
                            )

                return paths
        return paths

    @staticmethod
    def _process_with_regex(line: str) -> str:
        """
        Convert regexp to all possible outputs that match expression and expand it using
        external parameters. In-depth regexp documentation is in private method:
        RouteComputer._place_name.
        """
        expression = r"(\(\?:)([\/]?)(\(\?P<)([^>]*)(>)(([^|)]*\|?)*)(\){2})(\??)|(#\^)|(\[\/]\*\$#)|(\/\*\$#)"  # noqa: E501
        return re.sub(expression, RouteComputer._drop_regex_syntax, line)

    def _parse_single_line(
        self, line: str, options: Optional[Dict[str, List[str]]] = None
    ) -> Optional[
        Union[List[str], str, Dict[str, Union[List[str], Dict[str, List[str]]]]]
    ]:
        """
        Sequence of actions to process an input line.
        """
        if options is None:
            options = {}
        parsed_dynamic = self._process_with_regex(line)
        parsed_static = self._expand_dynamic_paths(parsed_dynamic, options)

        parsed_static = self._remove_impossible_paths(parsed_static, options)
        return parsed_static

    def parse_all(self, regexes: List[str], options: Dict[str, List[str]]) -> List[str]:
        """
        Loop through all elements in regexes, convert from regex to dynamic path and
        expand dynamic paths into all possible options.
        """
        all_paths: List[str] = []
        for regex in regexes:
            all_paths += self._parse_single_line(regex, options)  # type: ignore
        return all_paths
