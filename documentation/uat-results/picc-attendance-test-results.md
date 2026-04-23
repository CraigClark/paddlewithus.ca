# Test results for attendance feature

1. On view/picc_attendance, do not display field labels
2. On view/picc_attendance, use the same layout we use for the participant and swim test view
3. on view view/picc_attendance, remove the link to profile (takes too much space on mobile)
4. on view/picc_attendance, link participant name to pofile
5. On view/picc_attendance, I have added a product selective view, add the module 
6. On view/picc_attendance, we should have today's date. Perhaps under the title. Since this is just visual information for the coach, we could do that in a twig template. It's just reassurance they are on the right page
7. on view/picc_attendance, "24 participants expected today" should be at the top
8. view/picc_attendance, we are using better_exposed_filters and views_selective_filters. Should they be requirements on the module
9. on check-in modal,  remove "Parent mentioned medication change." in help text. That's critical information and this isn't the place for it. Too easy to miss unless someone is reviewing attendance, and that could be days later
10. on checkout modal, keep the note field when 'other' is selected. This could be more important because we should explain why an unautorized person is picking up the kid
11. on checkout, if there is an error, you are taken to another page because the form doesn't validate. if validation can happen in the modal, that's best, but if that's a big technical overhead we can ignore it since filling out the for properly returns us to the view
12. on checkout, add option for 'self checkout'
13. if I check in a participant, then checkout a participant, then reset check-in, it shows as checkout with no checkin. You can work-around this by reset check-in, reset check-out, enter a new check-in, re-do checkout. That isn't an obvious path and seems convoluted. Maybe if checkout is true, reset check-in is disabled. If reset-checkout, then reset checkin reappears. I'd like your thoughts on this, any potential problems before we proceed.
14. For admin/attendance/history and /en/admin/attendance, move these to admin/people
15. For admin/attendance/history date filter isn't visible on the filters
16. only admin can access this /admin/attendance, which is fine, but it should not appear in the menu if I cannot access it. Currently commerce_manager sees the link but 403 if clicked on
17. on /attendance/history would be good to have a filter for 'participant contains' . I tried adding this, but there is no contains filter option
18. /config/picc/attendance should be added to the menu. all picc related config should be in the admin menu at admin > config > PICC