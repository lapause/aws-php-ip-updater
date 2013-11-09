# Basic AWS IP updater

### Purpose
Useful if you must access Amazon pretected resources from different locations or from an access point that does not have a fixed IP address.
- Script will open configured port(s) for your current IP to every configured security groups.
- Your current IP will then be saved in a storage file. On next run, if your IP changed, the script will first clean up references to the old one.

### Usage
```bash
$ php AwsSecurityGroupUpdater.php -g GROUP1 [-g GROUP2...] [-p PORT] [-t PROTOCOL] [--grabber URL] [--storage FILE]
```

#### Arguments
- **-g**: Group name, at least one group must be provided
- **-p**: Port to open, default: _22_
- **-t**: Protocol to open, default: _tcp_
- **--grabber**: URL to use to grab current device IP address, default: _http://icanhazip.com_
- **--storage**: Path to storage file, default: _~/.aws/lastip_

