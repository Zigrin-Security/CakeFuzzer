# Contributing to CakeFuzzer

`TODO:` [Examples of `contributing.md` files](https://docs.github.com/en/communities/setting-up-your-project-for-healthy-contributions/setting-guidelines-for-repository-contributors#examples-of-contribution-guidelines)

👍🎉 First off, thanks for taking the time to contribute! 🎉👍

The following is a set of guidelines for contributing to CakeFuzzer.
These are mostly guidelines, not rules.
Use your best judgment, and feel free to propose changes to this document in a pull request.

# Styleguides

## Python Styleguide

We use [black](https://github.com/psf/black), [pylint](https://github.com/pre-commit/mirrors-pylint) and [mypy](https://github.com/pre-commit/mirrors-mypy) for overall code formatting and static checks.

Make sure to install [pre-commit](https://pre-commit.com/) hooks when contributing.

```
pre-commit install
```

It's required for all pre-commit checks to pass for the PR to get accepted.
Make sure to address all errors and possibly warnings before submitting.

Running pre-commit could possibly reformat the code (eg. [black](https://github.com/psf/black)) if it's not aligned with guidelines. If you want to run reformatting manually use `pre-commit run black` to use projects configuration.

By default pre-commit checks only files that were modified.
If you want to manually run all pre-commit hooks on a repository, run `pre-commit run --all-files`.

## Git Commit Messages

There are no particular guidelines to git commit messages themselfes.
But we try to strongly enforce the guidelines for PR descriptions and merge commits.

Use one of the available templates for PRs, follow outlined steps and fill details accordingly.
The most critical thing is to well describe what the PR does and what's it for.
This will enable for better prioritization and smoother process.



# The Twelve-Factor App
CakeFuzzer implements rules from [The Twelve-Factor App](https://12factor.net) despite the fact that CakeFuzzer is not meant to be SaaS or even a web application by itself.
## Twelve-Factor App principles in CakeFuzzer
1. **Codebase** - branches: production, staging, developer1, developer2, feature_x. Define convention with the developer.
2. **Dependencies** - .venv, requirements.txt. Find out way to manage&isolate PHP/bash dependencies.
3. **Config** - *issue: They recommend env files. Yaml is preferred for CakeFuzzer as it allows for nested config and more complex types.*
4. **Backing** **services** - Interchangable in config files.
5. **Build, release, run** - *Doesn’t exist in CakeFuzzer architecture.*
6. **Process** - App does not assume that anything than a source code and configuration is on the filesystem.
7. **Port binding** - Achieved on the level of global processes and monitors. Each has assigned separate port.
8. **Concurrency** - Internally concurrency is done with asyncio and separate websocket servers. Externally it could be  done via separating attacks provided in the config files and spawning multiple processes. This however can have impact on the time based attacks. Potentially implement Forman ([http://blog.daviddollar.org/2011/05/06/introducing-foreman.html](http://blog.daviddollar.org/2011/05/06/introducing-foreman.html)). Also, good idea could be to deamonize the Fuzzer controller and treat [CakeFuzzer.py](http://CakeFuzzer.py) as a client. This would help  dockerizing everything. Could be similar to [https://www.youtube.com/watch?v=ERiAJbfZxL0](https://www.youtube.com/watch?v=ERiAJbfZxL0) minute 22:22
9. **Disposability** - Goal: Keep the startup/instrumentation time as low as few seconds. Implement graceful shutdown whenever SIGTERM occurs (this sounds like an overkill). Every process should be reentrant (can be safely shutdown, will release all locks and will return current task to the tasks queue - no queues implemented currently).
10. **Dev/prod parity** - *In theory there should be a small gap between production & dev branch. In practice, it does not make much sens in that app.* Important note: All app deploys should use the same backing services.
11. **Logs** - Logs to stdout are advised. No log parsing/handling on the app. It should be done on the environment level using service such as https://github.com/fluent/fluentd. Not sure if it makes sense to implement log parsing in this app. To be considered later.
12. **Admin processes** - Make sure to run all of the application scripts inside the virtual environment.
