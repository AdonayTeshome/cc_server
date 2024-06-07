# Credit Commons node (server)

This package provide a REST wrapper around the [Credit Commons node](cc-node). Use it if you want a standalone ledger or if your main application is not in PHP.

## About this reference implementation
PHP and Mysql are widely used and trusted over the long term, and the languages in which the developer is most proficient. The architecture presented some challenges and the solutions here should be considered provisional. The project is published as seven packages:

1. This packages which provides a REST wrapper around
1. The Credit Commons node - the reference implementation itself.
1. A library of php functions which call the Credit Commons API, in order to ease development. Used by the reference implementation to call other nodes and by
1. The developer client, a client side application that implements the whole API
1. A package that implements the accountStore API to provide some demo users.
1. A REST wrapper which implements the accountstore API, enabling you to connect your main social networking app to the credit commons even if it's not in php.
1. A REST server which acts as a wrapper around a business logic microservice, should you choose to use it. Business logic is a class with a single public function which appends entries to a transaction.

## Installation
### Prerequisites
1. PHP 8 with mysqli 
1. mariaDB / Mysql
1. composer
1. A web server (currently only apache2 is supported)
1. A REST Client which implements thte Credit Commons protocol
1. The web server may need write access to the root directory either to edit node.ini or if are using the default accountstore, to write to accountstore.json

### Options
Combinations of seven packages support several scenarios:

- Demo code and user is provided
- A Credit Commons node can be run as a REST service or incorporated into a php application
- The user store can also be incorporated into a PHP app or accessed by a non-PHP application via REST
- Business logic can be incorporated into a PHP app, accessed via REST or not used at all.

### Procedure to set up a standalone node.
To run a credit commons node as a web service:

  * Navigate to the directory you want to serve from e.g. /var/www/my_credcom_node and rund the following command:
  * $ composer create-project --stability dev credit-commons/cc-server --repository '{"type": "gitlab","url": "git@gitlab.com:credit-commons/cc-server"}' MY_CC_SERVER_DIR
  * Download this package to the directory you want to serve from 
  * Go to that directory and run "composer update"
  * Determine what AccountStore you will use and whether it will included as a class or as its own REST service. An example accountStore class is provided, with about 3 accounts defined in a json file.
  * Similarly for business logic sub service which is optional.
  * Set up a VirtualHost for the credit commons ledger service pointing to the current directory and other virtualhosts if needed for the accountStore and business logic sub services. restart the web server!
  * Don't forget to enter the site in your /etc/hosts file something like ``127.0.0.1 myccnode accounts.myccnode blogic.myccnode`` replacing myccnode for your virtualhost name.
  * create and setup a database using the queries in vendor/credit-commons/cc-node/install.sql
  * Navigate in the browser to the project root and you will land on the config page.
  * Enter the db credentials, accountstore and business logic class names or urls, the node name and privacy settings etc.
  * On submission you will be directed to the AccountStore config where you can add to the default three accounts.
  * (optional) If you want business logic, then configure by editing BlogicService/blogic.ini by hand.

## Tests
Run tests with:

    $ vendor/bin/phpunit tests/SingleNodeTest.php
