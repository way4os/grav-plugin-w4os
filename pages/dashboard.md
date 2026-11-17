---
template: w4os-admin
w4os: dashboard
cache_enable: false
visible: false
# The blocks of the helpers the dashboard shows, in this order (a block more is a line more), each a column wide;
# `- {block: <name>, wide: true}` for one across the whole width; `- {card: <name>}` for a card of the plugin
w4os_blocks:
  - new_users
  - card: regions
---
