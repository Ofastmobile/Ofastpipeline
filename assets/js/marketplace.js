document.addEventListener('DOMContentLoaded', function() {
    
    // --- Mobile Drawer Toggle ---
    const filterToggle = document.getElementById('mp-filter-toggle');
    const sidebar = document.getElementById('mp-sidebar');
    const overlay = document.getElementById('mp-sidebar-overlay');
    
    function toggleSidebar() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    if (filterToggle && sidebar && overlay) {
        filterToggle.addEventListener('click', toggleSidebar);
        overlay.addEventListener('click', toggleSidebar);
    }

    // --- Price Slider Visual Logic ---
    // The design has checkboxes for ranges, but also a visual track. 
    // We'll update the visual track based on which checkboxes are selected.
    const priceCheckboxes = document.querySelectorAll('input[name="price_range[]"]');
    const priceFill = document.querySelector('.mp-price-fill');
    const handleLeft = document.querySelector('.mp-price-handle.left');
    const handleRight = document.querySelector('.mp-price-handle.right');

    function updateVisualSlider() {
        if (!priceFill || !handleLeft || !handleRight) return;

        let minIndex = -1;
        let maxIndex = -1;

        priceCheckboxes.forEach((cb, index) => {
            if (cb.checked) {
                if (minIndex === -1) minIndex = index;
                maxIndex = index;
            }
        });

        if (minIndex === -1) {
            // Nothing selected, show full or empty
            priceFill.style.left = '0%';
            priceFill.style.right = '100%';
            handleLeft.style.left = '0%';
            handleRight.style.left = '0%';
            return;
        }

        const step = 100 / (priceCheckboxes.length - 1 || 1);
        const leftPercent = minIndex * step;
        const rightPercent = maxIndex * step;

        priceFill.style.left = `${leftPercent}%`;
        priceFill.style.right = `${100 - rightPercent}%`;
        handleLeft.style.left = `${leftPercent}%`;
        handleRight.style.left = `${rightPercent}%`;
    }

    priceCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateVisualSlider);
    });
    // Init visual slider on load
    updateVisualSlider();


    // --- AJAX Filtering Logic ---
    const filterForm = document.getElementById('mp-filter-form');
    const gridContainer = document.getElementById('mp-grid-container');
    const loadingOverlay = document.getElementById('mp-loading-overlay');
    const clearAllBtn = document.getElementById('mp-clear-all');

    if (!filterForm || !gridContainer) return;

    let abortController = null;

    function fetchProperties() {
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();

        loadingOverlay.classList.add('active');

        // Serialize form data into URLSearchParams
        const formData = new FormData(filterForm);
        const searchParams = new URLSearchParams(formData);

        // Fetch current URL with new search params
        const url = window.location.pathname + '?' + searchParams.toString();

        // Update URL bar without reloading
        window.history.pushState({}, '', url);

        fetch(url, {
            signal: abortController.signal,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.text())
        .then(html => {
            // Parse HTML and extract the grid part
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newGrid = doc.getElementById('mp-grid-container');
            
            if (newGrid) {
                gridContainer.innerHTML = newGrid.innerHTML;
            }
            loadingOverlay.classList.remove('active');
        })
        .catch(error => {
            if (error.name !== 'AbortError') {
                console.error('Error fetching properties:', error);
                loadingOverlay.classList.remove('active');
            }
        });
    }

    // Trigger fetch on any input change inside the form (except the search text input)
    filterForm.addEventListener('change', function(e) {
        if (e.target.tagName !== 'INPUT' || e.target.type !== 'text') {
            fetchProperties();
        }
    });

    // Trigger fetch on form submit (for the search bar)
    filterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        fetchProperties();
    });

    // Clear All
    if (clearAllBtn) {
        clearAllBtn.addEventListener('click', function() {
            filterForm.reset();
            updateVisualSlider();
            fetchProperties();
        });
    }
});
