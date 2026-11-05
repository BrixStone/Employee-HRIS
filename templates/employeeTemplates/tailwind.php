<?php
/**
 * Tailwind (CDN) + the moonlit palette used by the employee leave pages.
 * Shared so file_leave.php and leave_history.php don't each carry their own
 * copy of the same config. Output is identical to the old inline blocks.
 */
?>
<script src="https://cdn.tailwindcss.com"></script>
<script>
  // Moonlit palette — matches the HR workspace design system (hris-base.css)
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          sidebar: '#0e1430',
          mainbg: '#0b1022',
          cardbg: '#141b38',
          cardborder: '#2a3566',
          accent: '#3b82f6',
          accenthover: '#2f6ae0',
          textmuted: '#9aa6cf'
        },
        fontFamily: {
          sans: ['Inter', 'sans-serif']
        }
      }
    }
  }
</script>
