// WordPress Memos Widget JavaScript

class MemosWidget {
    constructor(container, apiUrl, pageSize = 5, contentLength = 65) {
        this.container = container;
        this.apiUrl = apiUrl;
        this.pageSize = pageSize;
        this.contentLength = contentLength;
        this.init();
    }

    async init() {
        try {
            const memos = await this.fetchMemos();
            this.renderMemos(memos);
        } catch (error) {
            console.error('Failed to initialize Memos widget:', error);
            this.renderError();
        }
    }

    async fetchMemos() {
        const response = await fetch(`${this.apiUrl}/api/v1/memos?pageSize=${this.pageSize}`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return await response.json();
    }

    renderMemos(memos) {
        console.log('API返回数据结构:', JSON.stringify(memos, null, 2));
        
        let memosArray = [];
        
        if (Array.isArray(memos)) {
            memosArray = memos;
        } else if (memos && typeof memos === 'object') {
            if (Array.isArray(memos.memos)) {
                memosArray = memos.memos;
            } else if (Array.isArray(memos.data)) {
                memosArray = memos.data;
            } else if (Array.isArray(memos.list)) {
                memosArray = memos.list;
            } else if (Array.isArray(memos.rows)) {
                memosArray = memos.rows;
            }
        }
        
        console.log('处理后的数据:', memosArray);
        
        if (!memosArray || memosArray.length === 0) {
            this.container.innerHTML = '<p>暂无动态</p>';
            return;
        }

        const ul = document.createElement('ul');
        ul.className = 'memos-list';

        memosArray.forEach(memo => {
            const li = document.createElement('li');
            li.className = 'memos-item';
            
            const content = document.createElement('div');
            content.className = 'memos-content';
            
            const truncatedContent = memo.content.length > this.contentLength 
                ? memo.content.substring(0, this.contentLength) + '...' 
                : memo.content;
            content.textContent = truncatedContent;

            const time = document.createElement('span');
            time.className = 'memos-time';
            const date = new Date(memo.createTime || memo.displayTime);
            time.textContent = date.toLocaleDateString();

            const more = document.createElement('a');
            more.className = 'memos-more';
            more.href = `${this.apiUrl}/m/${memo.name.split('/')[1]}`;
            more.textContent = '[more]';
            more.target = '_blank';

            li.appendChild(content);
            li.appendChild(time);
            li.appendChild(more);
            ul.appendChild(li);
        });

        this.container.innerHTML = '';
        this.container.appendChild(ul);

        const style = document.createElement('style');
        style.textContent = `
            .memos-list {
                list-style: none;
                padding: 0;
                margin: 0;
            }
            .memos-item {
                padding: 15px;
                margin-bottom: 12px;
                background-color: #f8f9fa;
                border-radius: 8px;
                transition: all 0.3s ease;
                position: relative;
            }
            .memos-item:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            }
            .memos-content {
                color: #2c3e50;
                font-size: 14px;
                line-height: 1.6;
                margin-bottom: 10px;
                word-wrap: break-word;
            }
            .memos-time {
                color: #94a3b8;
                font-size: 12px;
                margin-right: 10px;
            }
            .memos-more {
                color: #3b82f6;
                text-decoration: none;
                font-size: 12px;
                transition: color 0.2s ease;
            }
            .memos-more:hover {
                color: #2563eb;
                text-decoration: underline;
            }
            @media (max-width: 768px) {
                .memos-item {
                    padding: 12px;
                    margin-bottom: 10px;
                }
                .memos-content {
                    font-size: 13px;
                }
            }
        `;
        document.head.appendChild(style);
    }

    renderError() {
        this.container.innerHTML = '<p>获取Memos动态失败，请稍后重试</p>';
    }
}
// 使用示例：
// const widget = new MemosWidget(document.getElementById('memos-container'), 'https://memo.zengqueling.com');
