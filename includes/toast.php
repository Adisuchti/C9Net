<div id="toast-container"></div>

<style>
#toast-container {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 1000;
    display: flex;
    flex-direction: column-reverse;
    gap: 10px;
}

.toast {
    background-color: #2d2d2d;
    color: #ffffff;
    padding: 12px 24px;
    border-radius: 4px;
    min-width: 250px;
    max-width: 400px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    animation: slideIn 0.3s ease-in-out;
    border-left: 4px solid #2e8b57;
}

.toast.success {
    border-left-color: #2e8b57;
}

.toast.error {
    border-left-color: #dc3545;
}

.toast.warning {
    border-left-color: #ffc107;
}

.toast.info {
    border-left-color: #0dcaf0;
}

.toast-message {
    margin-right: 12px;
    word-break: break-word;
}

.toast-close {
    background: none;
    border: none;
    color: #ffffff;
    cursor: pointer;
    font-size: 18px;
    padding: 0;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast-close:hover {
    opacity: 1;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes fadeOut {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}
</style>

<script>
function showToast(message, type = 'info', duration = 5000) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    toast.innerHTML = `
        <span class="toast-message">${message}</span>
        <button class="toast-close" onclick="closeToast(this.parentElement)">×</button>
    `;
    
    container.appendChild(toast);
}

function closeToast(toast) {
    toast.style.animation = 'fadeOut 0.3s ease-in-out forwards';
    setTimeout(() => {
        toast.remove();
    }, 300);
}
</script>