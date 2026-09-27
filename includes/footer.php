    <footer class="footer-preview">
        <p>Intranet developed by Adrian Brachyukani in contract with Cinder 9 Contracting LTD. All rights reserved to C-9 Contracting LTD.</p>
    </footer>
    <script>
        // Enable horizontal scrolling with mouse wheel for weapon containers
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.is-weapon-container').forEach(container => {
                container.addEventListener('wheel', function(e) {
                    // Check if hovering over a card that actually has vertical overflow
                    const card = e.target.closest('.inv-card');
                    if (card && card.scrollHeight > card.clientHeight) {
                        // Allow vertical scroll inside the card
                        return;
                    }
                    
                    if (e.deltaY !== 0) {
                        e.preventDefault();
                        this.scrollLeft += e.deltaY;
                    }
                });
            });

            // Convert standard selects into custom dropdowns across the site
            const selects = document.querySelectorAll('select:not(.no-custom-select)');
            
            selects.forEach(select => {
                // Only run if not already processed
                if (select.dataset.customSelectProcessed) return;
                select.dataset.customSelectProcessed = 'true';

                // Hide original select
                select.style.display = 'none';
                
                // Create container
                const container = document.createElement('div');
                container.className = 'c9-custom-select-container';
                
                // Copy standard classes from select so layout styles apply
                if (select.className) {
                    container.className += ' ' + select.className;
                }
                
                select.parentNode.insertBefore(container, select.nextSibling);
                container.appendChild(select); // move select inside container
                
                // Create Display Box
                const displayBox = document.createElement('div');
                displayBox.className = 'c9-custom-select-box';
                
                const displayText = document.createElement('span');
                displayText.className = 'c9-custom-select-display';
                
                // Find initial selected text
                let initialText = '';
                if (select.options.length > 0) {
                    initialText = select.options[select.selectedIndex > -1 ? select.selectedIndex : 0].text;
                }
                displayText.textContent = initialText;
                
                const caret = document.createElement('span');
                caret.className = 'c9-custom-select-caret';
                caret.textContent = '▼';
                
                displayBox.appendChild(displayText);
                displayBox.appendChild(caret);
                container.appendChild(displayBox);
                
                // Create Options Container
                const optionsContainer = document.createElement('div');
                optionsContainer.className = 'c9-custom-select-options';
                container.appendChild(optionsContainer);
                
                // Populate Options
                const renderOptions = () => {
                    optionsContainer.innerHTML = '';
                    Array.from(select.options).forEach((option, index) => {
                        const optDiv = document.createElement('div');
                        optDiv.className = 'c9-custom-select-option';
                        if (select.selectedIndex === index) {
                            optDiv.classList.add('selected');
                        }
                        optDiv.textContent = option.text;
                        
                        optDiv.addEventListener('click', (e) => {
                            e.stopPropagation();
                            
                            select.selectedIndex = index;
                            // Trigger standard change event for any existing scripts
                            select.dispatchEvent(new Event('change'));
                            
                            displayText.textContent = option.text;
                            
                            Array.from(optionsContainer.children).forEach(el => el.classList.remove('selected'));
                            optDiv.classList.add('selected');
                            
                            optionsContainer.style.display = 'none';
                        });
                        
                        optionsContainer.appendChild(optDiv);
                    });
                };
                
                renderOptions();
                
                // Handle mutations on select options (e.g. dynamic load)
                const observer = new MutationObserver(() => {
                    renderOptions();
                    if (select.options.length > 0) {
                        displayText.textContent = select.options[select.selectedIndex > -1 ? select.selectedIndex : 0].text;
                    }
                });
                observer.observe(select, { childList: true });

                // Listen to change event in case code changes the select directly
                select.addEventListener('change', () => {
                     if (select.options.length > 0) {
                         displayText.textContent = select.options[select.selectedIndex > -1 ? select.selectedIndex : 0].text;
                     }
                });
                
                // Toggle Dropdown
                displayBox.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = optionsContainer.style.display === 'block';
                    
                    // Close all others
                    document.querySelectorAll('.c9-custom-select-options, #custom-role-options').forEach(el => el.style.display = 'none');
                    document.querySelectorAll('.c9-custom-select-container').forEach(el => el.classList.remove('open'));
                    
                    if (!isOpen) {
                        optionsContainer.style.display = 'block';
                        container.classList.add('open');
                        // Resync selected class just in case
                        Array.from(optionsContainer.children).forEach((opt, idx) => {
                            if (select.selectedIndex === idx) opt.classList.add('selected');
                            else opt.classList.remove('selected');
                        });
                    }
                });
            });
            
            // Close dropdowns on outside click
            document.addEventListener('click', () => {
                document.querySelectorAll('.c9-custom-select-options').forEach(el => el.style.display = 'none');
                document.querySelectorAll('.c9-custom-select-container').forEach(el => el.classList.remove('open'));
            });
        });
    </script>
</body>
</html>

