Newsletter Subscription
=======================

The "Newsletter Subscription" extension allows managing the subscribers list to your site's newsletter. To use it, just insert the following into a template:

    {{ newslettersubscription() }}
    
Basic functionality
-------------------

With just the above function, the extension provides:

- A database table to store your subscribers' data.
- A subscribe form.
- Two-phase subscription confirmation:
    
    1. User submits the subscription form.
    2. User receives a confirmation email with a confirmation link.
    3. User clicks the confirmation link and the subscription is confirmed.
    
- Cancel subscription via an unsubscription link.
- Logged-in users (Admins and Developers) can download the list of subscribers as a CSV file.
- Subscription, confirmation and unsubscription emails are sent to users.
- Subscription and unsubscription notification emails are sent to a predefined address.    

Settings
--------

There are a lot of settings available in `config.yml`. All of them are self documented in the file itself, so there is no point in repeating it here. Just remember that `config.yml.dist` stores all the default values, so you only need to set a given setting if you want to overwrite defaults.