---
template: w4os-admin
w4os: edit
cache_enable: false
visible: false
form:
  name: w4os-edit
  classes: 'w4os-form w4os-form-profile'
  fields:
    avatar:
      type: hidden
    # The sections of a profile, in the order of the viewer's tabs
    profile:
      type: tabs
      fields:
        inworld:
          type: tab
          title: w4os.profile_tab_inworld
          fields:
            identity:
              type: w4os_identity
            about:
              type: textarea
              classes: w4os-autogrow
              label: w4os.about
              rows: 5
            groups:
              type: w4os_groups
            published:
              type: checkbox
              label: w4os.show_in_search
        web:
          type: tab
          title: w4os.profile_tab_web
          fields:
            profileUrl:
              type: url
              label: w4os.profile_url
            # A new password, set by the manager (empty: unchanged)
        interests:
          type: tab
          title: w4os.profile_tab_interests
          fields:
            interests_note:
              type: w4os_note
              text: w4os.interests_removed
            wants_group:
              type: fieldset
              legend: w4os.wants
              classes: w4os-interests
              fields:
                wantsList:
                  type: checkboxes
                  label: false
                  classes: w4os-flags
                  # Its choices: the engine's (x-form)
                  options: {}
                wants:
                  type: text
                  label: false
                  title: w4os.wants
            skills_group:
              type: fieldset
              legend: w4os.skills
              classes: w4os-interests
              fields:
                skillsList:
                  type: checkboxes
                  label: false
                  classes: w4os-flags
                  # Its choices: the engine's (x-form)
                  options: {}
                skills:
                  type: text
                  label: false
                  title: w4os.skills
            languages:
              type: w4os_languages
              label: w4os.languages
        picks:
          type: tab
          title: w4os.profile_tab_picks
          fields:
            picks_list:
              type: w4os_items
              kind: picks
        classifieds:
          type: tab
          title: w4os.profile_tab_classifieds
          fields:
            classifieds_list:
              type: w4os_items
              kind: classifieds
        real_life:
          type: tab
          title: w4os.profile_tab_real_life
          fields:
            first_picture:
              type: w4os_photo
              picture: firstImage
            firstLife:
              type: textarea
              classes: w4os-autogrow
              label: w4os.real_life
              rows: 3
        account:
          type: tab
          title: w4os.profile_tab_account
          fields:
            role:
              type: select
              label: w4os.role
              # Its choices: the engine's (x-form)
              options: {}
            email:
              type: email
              label: w4os.email
            password:
              type: password
              label: w4os.new_password
              help: w4os.password_help
              autocomplete: new-password
              minlength: 6
              maxlength: 16
            password_confirm:
              type: password
              label: w4os.password_confirm
              autocomplete: new-password
              minlength: 6
              maxlength: 16
  buttons:
    # Save, at the far right (the profile page of the avatar is a link of the header of the page)
    - { type: submit, value: w4os.save_button, classes: 'btn-primary' }
  process:
    - w4os: edit
---
