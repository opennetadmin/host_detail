host_detail
===========

This is a ONA plugin that enables a new `dcm.pl` command line module called `host_detail`.  This module is similar to the built in module `host_display` except that it outputs much more information about a host and it can output it in several formats.  Currently `yaml` and `json` are supported output formats. The intent is that this output is more suitable for programs to process and utilize.

Install
=======

Install as a standard plugin for ONA.

It uses `json_encode` or `yaml_emit` to format the output so the appropriate php functionality must be installed and available on your system for this to function.

Usage
=====

```
host_detail-v1.00
Display detailed info about a host and it's interfaces

  Synopsis: host_detail [KEY=VALUE] ...

  Required:
    host=FQDN|IP           FQDN or IP of host

  Optional:
    format=[yaml|json]     Output in yaml or json. Default: yaml
```

