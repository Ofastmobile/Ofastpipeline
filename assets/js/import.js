/**
 * OFast Pipeline — Import Module JavaScript
 * Handles drag & drop, file selection, and visual feedback for file uploads.
 */
(function() {
    'use strict';

    function handleFileSelected(input) {
        if (!input) return;
        if (input.files && input.files[0]) {
            var file = input.files[0];
            var sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            var textElem = document.getElementById('ofp-dropzone-text');
            if (textElem) {
                textElem.innerHTML = 'Selected: <strong>' + (file.name || 'File') + '</strong> (' + sizeMb + ' MB)';
                textElem.style.color = '#10b981';
            }
        }
    }

    // Expose globally for backward compatibility
    window.handleFileSelected = handleFileSelected;

    document.addEventListener('DOMContentLoaded', function() {
        var dropzoneBox = document.getElementById('ofp-dropzone-box');
        var fileInput   = document.getElementById('import_file');

        if (fileInput) {
            fileInput.addEventListener('change', function() {
                handleFileSelected(this);
            });
        }

        if (dropzoneBox) {
            ['dragenter', 'dragover'].forEach(function(eventName) {
                dropzoneBox.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzoneBox.classList.add('dragover');
                }, false);
            });

            ['dragleave', 'drop'].forEach(function(eventName) {
                dropzoneBox.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzoneBox.classList.remove('dragover');
                }, false);
            });

            dropzoneBox.addEventListener('drop', function(e) {
                var dt = e.dataTransfer;
                if (dt && dt.files && dt.files.length && fileInput) {
                    fileInput.files = dt.files;
                    handleFileSelected(fileInput);
                }
            }, false);
        }
    });
})();
