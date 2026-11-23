<?php
// Booking is now handled by the embedded booking form on /book.html.
// Keep this URL working for old links and bookmarks.
header('Location: /book.html', true, 301);
exit;
