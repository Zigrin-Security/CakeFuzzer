<?php
function __get_http_path_complex_dict_UNUSED($path, $payloads) {
    /* Here is an example JSON that can be parsed by this function:
            {
                "type": "all",
                "values": [
                    self.path,
                    {
                        "type": "string",
                        "default": ""
                    },
                    {
                        "type": "any",
                        "values": [
                            "",
                            "/",
                            {
                                "type": "all",
                                "values": [
                                    "/",
                                    {
                                        "type": "string",
                                        "default": ""
                                    },
                                    {
                                        "type": "any",
                                        "values": ["", "/"]
                                    }
                                ]
                            },
                            {
                                "type": "all",
                                "values": [
                                    "/",
                                    {
                                        "type": "string",
                                        "default": ""
                                    },
                                    "/",
                                    {
                                        "type": "all",
                                        "values": [
                                            {
                                                "type": "string",
                                                "default": ""
                                            },
                                            {
                                                "type": "any",
                                                "values": ["", "/"]
                                            }
                                        ]
                                    }
                                ]
                            },
                        ]
                    }
                ]
            }
     */
    $probability = 50;
    if(is_string($path)) return $path;
    if(is_array($path)) {
        if(empty($path["type"])) throw Exception("[!] Error: Path fragment does not contain 'type' key.");
        $result = '';
        switch($path["type"]) {
            case "string":
                $result = '';
                if(!empty($path["default"])) $result = $path["default"];
                if(rand() % 100 < $probability) $result = $payloads[array_rand($payloads)];
                break;
            case "any":
                $result = '';
                if(empty($path["values"])) logWarning("Path fragment type 'any' has no key: 'values'");
                $result = __get_http_path_complex_dict_UNUSED($path["values"][array_rand($path["values"])], $payloads);
                break;
            case "all":
                if(empty($path["values"])) logWarning("Path fragment type 'all' has no key: 'values'");
                foreach($path["values"] as $fragment) {
                    $result .= __get_http_path_complex_dict_UNUSED($fragment, $payloads);
                }
                break;
            default:
                logWarning("Unsupported path fragment type: ${path['type']}");
        }
        return $result;
    }
    throw Exception("[!] Error: Path variable cannot be type other than string or array. '".type($path)."' given.");
}
