// WordPress Memos Widget JavaScript

class MemosWidget {
    constructor(container, apiUrl, pageSize = 5, contentLength = 65, accessToken = '') {
        this.container = container;
        this.apiUrl = (apiUrl || '').replace(/\/+$/, '');
        this.pageSize = pageSize;
        this.contentLength = contentLength;
        this.accessToken = accessToken;
        this.init();
    }

    async init() {
        try {
            const memos = await this.fetchMemos();
            this.renderMemos(memos);
        } catch (error) {
            console.error('Failed to initialize Memos widget:', error);
            this.renderError(error);
        }
    }

    async fetchMemos() {
        const headers = {};
        if (this.accessToken) {
            headers['Authorization'] = `Bearer ${this.accessToken}`;
        }

        const response = await fetch(`${this.apiUrl}/api/v1/memos?pageSize=${this.pageSize}`, {
            headers: headers
        });

        if (!response.ok) {
            let errorMsg = `HTTP error! status: ${response.status}`;
            if (response.status === 401) {
                errorMsg = '401 Unauthorized';
            }
            throw new Error(errorMsg);
        }
        return await response.json();
    }

    renderMemos(memos) {
        console.log('API返回数据结构:', memos);
        
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
            
            const rawContent = memo.content || '';
            const truncatedContent = rawContent.length > this.contentLength 
                ? rawContent.substring(0, this.contentLength) + '...' 
                : rawContent;
            content.textContent = truncatedContent;

            const time = document.createElement('span');
            time.className = 'memos-time';
            
            const rawTime = memo.createTime || memo.displayTime || memo.createdTs;
            let date;
            if (typeof rawTime === 'number') {
                date = new Date(rawTime * 1000);
            } else if (rawTime) {
                date = new Date(rawTime);
            } else {
                date = new Date();
            }
            time.textContent = isNaN(date.getTime()) ? '' : date.toLocaleDateString();

            const more = document.createElement('a');
            more.className = 'memos-more';

            let memoId = '';
            if (memo.uid) {
                memoId = memo.uid;
            } else if (memo.name && typeof memo.name === 'string' && memo.name.includes('/')) {
                memoId = memo.name.split('/')[1];
            } else if (memo.id) {
                memoId = memo.id;
            }

            more.href = memoId ? `${this.apiUrl}/m/${memoId}` : `${this.apiUrl}`;
            more.textContent = '[more]';
            more.target = '_blank';

            li.appendChild(content);
            li.appendChild(time);
            li.appendChild(more);
            ul.appendChild(li);
        });

        this.container.innerHTML = '';
        this.container.appendChild(ul);

        if (!document.getElementById('memos-widget-style')) {
            const style = document.createElement('style');
            style.id = 'memos-widget-style';
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
                .memos-error {
                    color: #ef4444;
                    font-size: 13px;
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
    }

    renderError(error) {
        if (error && error.message && error.message.includes('401')) {
            this.container.innerHTML = '<p class="memos-error">获取Memos动态失败：401未授权（新版Memos请在插件设置中配置 Access Token）</p>';
        } else {
            this.container.innerHTML = '<p class="memos-error">获取Memos动态失败，请稍后重试</p>';
        }
    }
}
// 使用示例：
// const widget = new MemosWidget(document.getElementById('memos-container'), 'https://memo.zengqueling.com');
