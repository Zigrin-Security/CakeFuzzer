import pytest

from cakefuzzer.instrumentation.route_computer import RouteComputer


@pytest.mark.parametrize(
    "tested,expected",
    [
        ("#^/*$#", "/"),
        ("#^/events[/]*$#", "/events/"),
        ("#^/(?:(?P<controller>[^/]+))[/]*$#", "/~controller~/"),
        ("#^(?:/(?P<plugin>aad_auth|apcu_cache))[/]*$#", "/~aad_auth|apcu_cache~/"),
        (
            "#^/events(?:/(?P<id>[0-9]+|[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{12}))["
            "/]*$#",
            "/events/~id~/",
        ),
        (
            "#^/admin/(?:(?P<controller>[^/]+))/(?:(?P<action>[^/]+))(?:/(?P<_args_>.*))?[/]*$#",
            "/admin/~controller~/~action~/~args~optional~/",
        ),
        (
            "#^/(?:(?P<controller>[^/]+))/(?:(?P<action>[^/]+))(?:/(?P<_args_>.*))?[/]*$#",
            "/~controller~/~action~/~args~optional~/",
        ),
    ],
)
def test_process_with_regex(tested, expected):
    assert RouteComputer._process_with_regex(tested) == expected


@pytest.mark.parametrize(
    "tested,options,expected",
    [
        ("/", {}, ["/"]),
        (
            "/~controller~/",
            {},
            [],
        ),  # we decided, that dynamic segments with no options as parameters
        # will be skipped for now
        (
            "/~controller~/",
            {"controller": ["controller_1", "controller_2"]},
            ["/controller_1/", "/controller_2/"],
        ),
        (
            "/~aad_auth|apcu_cache~/",
            {},
            ["/aad_auth/", "/apcu_cache/"],
        ),  # no need for options if alternatives were
        # specified in original regexp
        (
            "/admin/~controller~/~action~/~args~optional~/",
            {
                "controller": ["controller_1", "controller_2"],
                "action": {
                    "controller_1": ["action_1", "action_2"],
                    "controller_2": ["action_3", "action_4"],
                },
            },
            [
                "/admin/controller_1/action_1/",
                "/admin/controller_1/action_2/",
                "/admin/controller_2/action_3/",
                "/admin/controller_2/action_4/",
            ],
        ),
    ],
)
def test_expand_dynamic_paths(tested, options, expected):
    inp = tested.split(
        "/"
    )  # this is a step that was required to simplify computation process
    assert RouteComputer._expand_dynamic_paths(inp, options) == expected
