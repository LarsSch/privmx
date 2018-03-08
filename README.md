

# PrivMX WebMail README


## About PrivMX WebMail

PrivMX WebMail is an alternative, private mail system which is easy to install and use. It uses strong encryption to make your correspondence private. All messages are sent through servers and are kept on servers, but are unreadable for them and their admins. Mails and attachments are encrypted on your computer before they are sent, and can be decrypted and read only by addressees. 

PrivMX WebMail is a decentralized system, just like standard email. 
Remember - PrivMX addresses are different - they contain # instead of @. 

PrivMX WebMail is distributed under the PrivMX Web Freeware License. See LICENSE.txt.


## Requirements

If your web page uses PHP 5.4 or newer, then you are ready to install PrivMX WebMail :)


## Installation

PrivMX WebMail uses popular PHP and contains dedicated installation script, so installation is quick and easy. Let's assume that your web page has address https://yourdomain.net, then the standard and simpliest installation procedure is to:

* download PrivMX WebMail package and unzip it in the main folder of your web page. It should create new subfolder /privmx - make sure that it is writable for your web server.  
* Use your web browser to open https://yourdomain.net/privmx, and follow the information provided by the installation script. The script doesn't download anything, it performs some operations in the /privmx folder and generates activation link for the first (admin) account. 
* Activate the first account - you'll get your PrivMX address yourname#yourdomain.net 

That's it! Now you can login and create accounts for other users... Remember that:
* your login page is https://yourdomain.net/privmx, and that 
* you can always test your configuration by sending anything from your account to bot#simplito.com. The bot should respond after few seconds with a copy (echo) of your message. 


## Updating

PrivMX WebMail uses its own update system and periodically checks update server for new versions of the software. You can also use the Server Admin window to check for updates manually. When a new version is found, it can be easily downloaded and installed - just click an adequate button in the window. You can also manually download new versions and update your server by replacing appropriate files. 


## More Information & troubleshooting

For more information about PrivMX Webmail, its features, installing and updating, please visit https://privmx.com
 

## Who we are

We are Simplito, a team of programmers from Poland. For over 10 years we've been working with our customers on advanced programming projects. PrivMX WebMail is our idea and our own software, which can be seen as a PHP+JavaScript implementation of the PrivMX Architecture. We design the latter, because we want to have well-designed client-side encryption tools for use in our customers' applications. Learn more at https://simplito.com

Thank you for your interest in PrivMX!
