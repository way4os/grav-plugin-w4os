---
template: default
w4os: profile
w4os_block: profile
cache_enable: false
visible: false
# The form an avatar writes a post with, on its own profile: its field and its button as the engine describes them (x-form of the
# posts), set when the form is made
form:
  name: w4os-post
  classes: 'w4os-form w4os-post-form'
  fields:
    content:
      type: textarea
  buttons:
    - { type: submit, value: w4os.action_post, classes: 'button primary' }
  process:
    - w4os: post
---
