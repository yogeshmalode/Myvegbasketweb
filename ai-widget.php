<style>
  .basket-ai-widget,
  .basket-ai-widget * {
    box-sizing: border-box;
  }

  .basket-ai-launcher {
    position: fixed;
    z-index: 1100;
    right: 90px;
    bottom: 22px;
    display: grid;
    width: 58px;
    height: 58px;
    place-items: center;
    border: 0;
    border-radius: 50%;
    background: linear-gradient(135deg, #10b981, #047857);
    box-shadow: 0 8px 24px rgba(4, 120, 87, .35);
    color: #fff;
    cursor: pointer;
    transition: transform .2s ease, box-shadow .2s ease;
  }

  .basket-ai-launcher:hover {
    transform: translateY(-2px);
    box-shadow: 0 11px 28px rgba(4, 120, 87, .42);
  }

  .basket-ai-launcher svg {
    width: 27px;
    height: 27px;
  }

  .basket-ai-panel {
    position: fixed;
    z-index: 1101;
    right: 22px;
    bottom: 94px;
    display: flex;
    width: min(380px, calc(100vw - 32px));
    height: min(500px, calc(100dvh - 120px));
    min-height: min(330px, calc(100dvh - 120px));
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #dce9e2;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 16px 48px rgba(15, 23, 42, .2);
    transform-origin: bottom right;
    animation: basket-ai-enter .18s ease-out;
  }

  .basket-ai-panel[hidden] {
    display: none;
  }

  .basket-ai-header {
    display: flex;
    min-height: 62px;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: linear-gradient(110deg, #10b981, #047857);
    color: #fff;
  }

  .basket-ai-title {
    margin: 0;
    font: 700 16px/1.3 system-ui, sans-serif;
  }

  .basket-ai-subtitle {
    margin: 3px 0 0;
    color: rgba(255, 255, 255, .86);
    font: 12px/1.3 system-ui, sans-serif;
  }

  .basket-ai-close {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, .15);
    color: #fff;
    cursor: pointer;
  }

  .basket-ai-messages {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 12px;
    overflow-y: auto;
    padding: 16px 13px;
    background: #f7faf8;
    overscroll-behavior: contain;
  }

  .basket-ai-bubble {
    max-width: 86%;
    padding: 10px 12px;
    border-radius: 15px;
    color: #1f2937;
    font: 14px/1.5 system-ui, sans-serif;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
  }

  .basket-ai-bubble--assistant {
    align-self: flex-start;
    border: 1px solid #e4eee8;
    border-bottom-left-radius: 5px;
    background: #edf7f0;
  }

  .basket-ai-bubble--user {
    align-self: flex-end;
    border-bottom-right-radius: 5px;
    background: #047857;
    color: #fff;
  }

  .basket-ai-bubble--typing {
    color: #557064;
  }

  .basket-ai-bubble--typing::after {
    display: inline-block;
    width: 7px;
    height: 7px;
    margin-left: 7px;
    border-radius: 50%;
    background: #10b981;
    content: "";
    animation: basket-ai-pulse .8s ease-in-out infinite alternate;
  }

  .basket-ai-form {
    display: flex;
    gap: 8px;
    padding: 11px;
    border-top: 1px solid #e5eee8;
    background: #fff;
  }

  .basket-ai-input {
    min-width: 0;
    flex: 1;
    padding: 11px 13px;
    border: 1px solid #d5e2da;
    border-radius: 22px;
    outline: none;
    color: #1f2937;
    font: 14px system-ui, sans-serif;
  }

  .basket-ai-input:focus {
    border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, .13);
  }

  .basket-ai-send {
    display: grid;
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    place-items: center;
    border: 0;
    border-radius: 50%;
    background: #047857;
    color: #fff;
    cursor: pointer;
  }

  .basket-ai-send:disabled {
    cursor: wait;
    opacity: .6;
  }

  .basket-ai-send svg {
    width: 19px;
    height: 19px;
  }

  @keyframes basket-ai-pulse {
    from { opacity: .3; transform: scale(.75); }
    to { opacity: 1; transform: scale(1); }
  }

  @keyframes basket-ai-enter {
    from { opacity: 0; transform: translateY(10px) scale(.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }

  @media (max-width: 480px) {
    .basket-ai-panel {
      right: 12px;
      bottom: 86px;
      width: calc(100vw - 24px);
      height: min(500px, calc(100dvh - 106px));
    }

    .basket-ai-launcher {
      right: 82px;
      bottom: 16px;
      width: 54px;
      height: 54px;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .basket-ai-launcher,
    .basket-ai-panel,
    .basket-ai-bubble--typing::after {
      animation: none;
      transition: none;
    }
  }
</style>

<div class="basket-ai-widget">
  <button class="basket-ai-launcher" type="button" aria-label="Open chat with Basket" aria-expanded="false" aria-controls="basket-ai-panel">
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5 8 8 0 0 1-3.1-.62L4 20l1.62-4.05A7.47 7.47 0 0 1 4.5 11.5 7.5 7.5 0 0 1 12 4h.5a7.5 7.5 0 0 1 7.5 7.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M8 11.5h8M8 14.5h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
    </svg>
  </button>

  <section class="basket-ai-panel" id="basket-ai-panel" aria-label="Chat with Basket" hidden>
    <header class="basket-ai-header">
      <div>
        <h2 class="basket-ai-title">Chat with Basket 🧺</h2>
        <p class="basket-ai-subtitle">Your chats may be saved to help with support</p>
      </div>
      <button class="basket-ai-close" type="button" aria-label="Close chat">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true">
          <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>
    </header>

    <div class="basket-ai-messages" role="log" aria-live="polite" aria-relevant="additions text">
      <div class="basket-ai-bubble basket-ai-bubble--assistant">Hi! I’m Basket 🧺. How can I help with groceries, cooking, or your MyVegBasket order?</div>
    </div>

    <form class="basket-ai-form">
      <input class="basket-ai-input" type="text" name="message" maxlength="2000" placeholder="Ask Basket anything..." autocomplete="off" aria-label="Message Basket" required>
      <button class="basket-ai-send" type="submit" aria-label="Send message">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="m21 3-7.2 18-3.5-7.3L3 10.2 21 3Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M10.3 13.7 15 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
      </button>
    </form>
  </section>
</div>

<script>
  (() => {
    const widget = document.querySelector('.basket-ai-widget');
    if (!widget) return;

    const launcher = widget.querySelector('.basket-ai-launcher');
    const panel = widget.querySelector('.basket-ai-panel');
    const closeButton = widget.querySelector('.basket-ai-close');
    const form = widget.querySelector('.basket-ai-form');
    const input = widget.querySelector('.basket-ai-input');
    const sendButton = widget.querySelector('.basket-ai-send');
    const messages = widget.querySelector('.basket-ai-messages');

    const scrollToLatest = () => {
      messages.scrollTop = messages.scrollHeight;
    };

    const addBubble = (text, type) => {
      const bubble = document.createElement('div');
      bubble.className = `basket-ai-bubble basket-ai-bubble--${type}`;
      bubble.textContent = text;
      messages.appendChild(bubble);
      scrollToLatest();
      return bubble;
    };

    const setOpen = (open) => {
      panel.hidden = !open;
      launcher.setAttribute('aria-expanded', String(open));
      launcher.setAttribute('aria-label', open ? 'Close chat with Basket' : 'Open chat with Basket');
      if (open) input.focus();
    };

    launcher.addEventListener('click', () => setOpen(panel.hidden));
    closeButton.addEventListener('click', () => setOpen(false));

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const message = input.value.trim();
      if (!message || sendButton.disabled) return;

      addBubble(message, 'user');
      input.value = '';
      sendButton.disabled = true;
      const typingBubble = addBubble('Basket is typing...', 'assistant');
      typingBubble.classList.add('basket-ai-bubble--typing');

      try {
        const response = await fetch(<?= json_encode(rtrim(BASE_URL, '/') . '/ai-handler.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: new URLSearchParams({ message })
        });
        const data = await response.json();
        typingBubble.remove();
        if (!response.ok || typeof data.text !== 'string') {
          throw new Error(typeof data.error === 'string' ? data.error : 'Please try again in a moment.');
        }
        addBubble(data.text, 'assistant');
        if (data.logged === false) {
          addBubble(data.warning || 'This reply was not saved to the chat log.', 'assistant');
        }
      } catch (error) {
        typingBubble.remove();
        addBubble(error instanceof Error ? error.message : 'Basket could not reply just now. Please try again.', 'assistant');
      } finally {
        sendButton.disabled = false;
        input.focus();
      }
    });
  })();
</script>
