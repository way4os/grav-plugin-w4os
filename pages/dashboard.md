---
template: w4os-admin
w4os: dashboard
cache_enable: false
visible: false
# The cards of the dashboard, in this order: a list of the admin (`page`: manage, regions, simulators) and the filters it keeps
# (`filter`), as the number of its rows, leading to the list so filtered; or its first rows as it sorts them (`rows`), each leading
# to its page (`item`)
w4os_cards:
  - { page: regions, filter: { status: online } }
  - { page: simulators, filter: { running: 'yes' } }
  - { page: manage, filter: { online: online } }
  - { page: manage, filter: { status: pending } }
  - { page: manage, rows: 5, item: edit }
# Blocks of the helpers after the cards (none by default): the name of a block, or {block: <name>, wide: true, ...} across the
# whole width, with its arguments
w4os_blocks: []
---
