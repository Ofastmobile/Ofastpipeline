OK if you check activity log. I noticed that email content are not there tge ones that are there has wrong content,  something link overdue email,  the body will say fund approved,  by tge correct content is in sms. Sms might not deliver cz sms channels are not reliable like email . That is for installment stuff, I can even say if rent is OK too. 

I want you to help me check all email messages across the platform, from sign up to the very least, (Though sign up, invite team, subscription and it crons are OK but you can verify to if it needs polishing) if all the email matches their events .

claude response:
I traced every email in the plugin against its event. Here is what I found, with the likely cause of your overdue and fund approved mix up first.
Why the wrong content shows (likely cause)
The email preview in the activity log (ajax_get_email_preview) has a fallback. If a log row has no saved body, it grabs the latest email sent to that same address, or the latest notification with the same title. When you test with your own email, an old overdue row can show the newest "Funding approved" email. I cannot say for sure why those rows had no body, so please tell me if they are old rows.
Why some emails are missing
OFP_Mailer::send finds the owner of an email by checking clients, team, leads and buyers. It never checks tenants. So rent emails to tenants (lease reminders, rent received, rent confirmed, rent not confirmed) are saved with no client. They never show in that client's activity log.
Other problems
sync_historical_activity also copies logs with no client into every client's communications log. One client can see other people's email text. This is the most serious one.
Resending an offer sends two different emails, one saying "reminder" and one saying "has been created". It also creates a new owner notification each time.
The installment reminder email says "use your secure payment link" but has no link. The lease reminder has no payment details either.
Resend from the log uses the plain text version, so links and buttons are lost. Preview uses the HTML version. They should match.
Two events fire with nobody listening, so no email goes out: purchase completed (all installments paid) and offer accepted.
Polish: many subjects use a dash, like "Payment received — ...". Offer emails say NGN while others say ₦. Offer emails also put the buyer name in the HTML without escaping.
Checked and matching their events
Purchase recorded, payment received, payment waiting, payment not confirmed, rent waiting, confirmed and not confirmed, lease reminders, installment reminders (text matches subject), funding emails, welcome, approval, password reset, low credit, monthly report and subscription reminder.
I did not check the admin side email preview, or the exact wording of team invite and signup emails line by line.
My advice is to fix in this order: tenant lookup and the cross client leak, then the preview and resend, then the missing links, then the double offer email, then the two missing emails, then the subject polish.


my addition:

Rent activity log should be in rent & tenant page table , or it's not there, or it just their transactions that is there? If not there then let bring it out to activity log too. 

What is in admin is what is brought to client dashboard,  so any fixing should be on both sides.