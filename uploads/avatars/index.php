<?php
// Prevent directory listing of uploaded avatars.
http_response_code(403);
exit('Forbidden');
