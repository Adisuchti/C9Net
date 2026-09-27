<div id="error-overlay" style="display: none;">
    <div id="error-overlay-container">
        <h3 id="error-title">Error</h3>
        <div id="error-content">
            <p id="error-message"></p>
        </div>
        <div class="error-actions">
            <button type="button" class="error-close-btn" onclick="closeErrorOverlay()">Close</button>
        </div>
    </div>
</div>

<style>
#error-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
}

#error-overlay-container {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background-color: #fff;
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    max-width: 500px;
    width: 90%;
}

#error-title {
    margin: 0 0 15px 0;
    color: #dc3545;
}

#error-content {
    margin-bottom: 20px;
}

#error-message {
    margin: 0;
    line-height: 1.5;
    color: #000;
}

.error-actions {
    text-align: right;
}

.error-close-btn {
    padding: 8px 16px;
    background-color: #6c757d;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.error-close-btn:hover {
    background-color: #5a6268;
}
</style>

<script>
function showError(message, title = 'Error') {
    document.getElementById('error-title').textContent = title;
    document.getElementById('error-message').textContent = message;
    document.getElementById('error-overlay').style.display = 'block';
}

function showWarning(message) {
    showError(message, 'Warning');
    document.getElementById('error-title').style.color = '#ffc107';
}

function closeErrorOverlay() {
    document.getElementById('error-overlay').style.display = 'none';
}
</script>