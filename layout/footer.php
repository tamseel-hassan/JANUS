    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Theme toggle
        document.getElementById('themeSwitch')?.addEventListener('change', function() {
            const theme = this.checked ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.cookie = "theme=" + theme + "; path=/; max-age=" + 60*60*24*365;
        });
    </script>
</body>
</html>
