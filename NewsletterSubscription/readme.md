Newsletter Subscription
=======================

---

This is a **Bolt** extension. You can read more about the awesome Bolt CMS built on top of Silex microframework at [Bolt.cm](http://bolt.cm).  

---

The "Newsletter Subscription" extension provides automated management of a list of newsletter subscribers. **It does not deal with the actual sending of the newsletter**, only with the subscription/desubscription of users. You then need to use the list of subscribers to do the send yourself using any suitable method. 

License
-------

This extension is licensed under the MIT license.


Basic usage
-----------

To use it, just insert the following into a template:

    {{ newslettersubscription() }}

    
Features
--------

With just the above Twig function, the extension provides:

- A database table to store your subscribers' data.
- A subscribe form.
- Two-phase subscription confirmation:
    1. User submits the subscription form.
    2. User receives a confirmation email with a confirmation link.
    3. User clicks the confirmation link and the subscription is confirmed.
- Cancel subscription via an unsubscription link.
- A dashboard widget that will shown a download link to download the list of subscribers as a CSV file.
- Subscription, confirmation and unsubscription emails are sent to users.
- Subscription and unsubscription notification emails are sent to a predefined address.    

Settings
--------

There are a lot of settings available in `config.yml`. All of them are self documented in the file itself, so there is no point in repeating them here. Just remember that `config.yml.dist` stores all the default values, so you only need to set a given setting if you want to overwrite defaults. Do not modify `config.yml.dist`.

How to download the subscribers list
------------------------------------

There are two ways:

### 1. From the dashboard widget

You will find the *Newsletter Subscription* dashboard widget with basic statistics about your subscribers. 

The widget contains the link to download the subscribers CSV file. Just click the link and the file wil start downloading.  

**NOTE**: The download link will only work if the following conditions are all met:

- You are logged in to the backend.
- The `admin_secret` setting in `config.yml`has a value that is different from the default.

If you plan to download the subscribers list from another application you should use the "frontend link" method. 

### 2. From a frontend link 

You can also download the file without using the browser at all. Just use the download link in some other application 
able to perform http downloads. The link to use has the form:

    http://mybolt.com/page/newsletter?adminaction=download&secret=abc123
    
...where obviously `http://mybolt.com/page/newsletter` is the page with the newsletter subscription form and `abc123` is your `admin_secret` setting. 

Example:

    wget "http://mybolt.com/page/newsletter?adminaction=download&secret=abc123" -O subscribers.csv  
    
or 

    curl -s "http://mybolt.com/page/newsletter?adminaction=download&secret=abc123" > subscribers.csv

**NOTE**: The download link will only work if the following conditions are all met:

- The `admin_secret` setting has a value that is different from the default (`abc123` in the above example).
- The download url provides a `secret` argument with the same value as `admin_secret`.

So unauthorized people cannot get the valuable list of your subscribers. 

How to send the newsletter
--------------------------

That is completely up to you. Just download the subscribers list and, well, send them the newsletter... Remember that this extension just provide a means for your visitors to subscribe and unsubscribe.

**Please note** that the subscribers list contains a *unsubscribe link* that you must include in the message that you send to each user, to provide them a way to unsubscribe. The link contains `confirmkey` and `email` arguments, so it is personalized for each user in the same way the *confirm subscription* link is. 


TO-DO
-----

- TBD.

 
     

