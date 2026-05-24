# Woo Totobi Integration

WooCommerce plugin for importing selected Totobi products from the Prom YML feed.

## Current Stage

This is the initial scaffold. It includes:

- plugin bootstrap;
- WooCommerce admin submenu;
- settings storage;
- Prom YML feed URL setting;
- main YML fallback URL setting;
- automatic category list from the client task;
- manual category mode placeholder;
- daily WP-Cron hook;
- manual sync check button;
- feed fetch and catalog metadata parse;
- log file support.

Product import is not implemented yet.

## Feed Strategy

Primary source:

`https://totobi.com.ua/index.php?dispatch=yml.get&access_key=nnpnlo7d96a3`

Reason: Prom YML expands clothing variants into grouped offers via `group_id`, which maps more directly to WooCommerce variable products.

Main YML remains a fallback/reference source:

`https://totobi.com.ua/index.php?dispatch=yml.get&access_key=lg3bjy2gvww`

## Logs

Runtime log file:

`wp-content/uploads/woo-totobi-integration.log`

