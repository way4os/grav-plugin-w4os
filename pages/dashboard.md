---
template: w4os-admin
w4os: dashboard
cache_enable: false
visible: false
# The cards of the dashboard, in this order (w4os.php dashboard()): a block of the helpers (`block`, its arguments); how many of a
# list (`page`: manage, regions, simulators) its filter keeps (`part`) of all it knows, said by a text of the catalog (`text`);
# the counts of a list in one card (`counts`, a filter each), leading to the list so filtered; its first rows as it sorts them
# (`rows`, its `title`), each leading to its page (`item`)
w4os_cards:
  # The widgets of w4os-wp: the grid info and status, the recent users
  - { block: grid-info, title: dashboard_grid_info }
  - { block: grid-status, title: dashboard_grid_status }
  - { page: regions, part: { status: online }, text: regions_online }
  - { page: simulators, part: { running: 'yes' }, text: simulators_running }
  # The avatars online and pending, the avatar models and the service accounts, as the status page of w4os-wp counts them
  - { page: manage, counts: [{ online: online }, { status: pending }, { type: model }, { type: service }] }
  - { page: manage, rows: 5, item: edit, title: recent_avatars }
---
