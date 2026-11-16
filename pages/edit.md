---
template: w4os
w4os: edit
cache_enable: false
visible: false
form:
  name: w4os-edit
  classes: w4os-form
  fields:
    avatar:
      type: hidden
    # The sections of a profile, in the order of the viewer's tabs
    profile:
      type: tabs
      fields:
        second_life:
          type: tab
          title: w4os.profile_tab_second_life
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
                  options:
                    build: w4os.want_build
                    explore: w4os.want_explore
                    meet: w4os.want_meet
                    group: w4os.want_group
                    buy: w4os.want_buy
                    sell: w4os.want_sell
                    be-hired: w4os.want_be_hired
                    hire: w4os.want_hire
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
                  options:
                    textures: w4os.skill_textures
                    architecture: w4os.skill_architecture
                    event-planning: w4os.skill_event_planning
                    modeling: w4os.skill_modeling
                    scripting: w4os.skill_scripting
                    custom-characters: w4os.skill_custom_characters
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
        first_life:
          type: tab
          title: w4os.profile_tab_first_life
          fields:
            first_picture:
              type: w4os_photo
              picture: firstImage
            firstLife:
              type: textarea
              classes: w4os-autogrow
              label: w4os.first_life
              rows: 3
        account:
          type: tab
          title: w4os.profile_tab_account
          fields:
            role:
              type: select
              label: w4os.role
              options:
                resident: w4os.role_resident
                god-like: w4os.role_god_like
                customer-service: w4os.role_customer_service
                liaison: w4os.role_liaison
                god: w4os.role_god
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
    - { type: submit, value: w4os.save_button, classes: 'button primary' }
    # The profile page of the avatar (its address set when the form is)
    - { type: button, id: w4os-view-profile, value: w4os.action_view, url: '', classes: 'button secondary' }
  process:
    - w4os: edit
---
