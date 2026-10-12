# Workflow 3 —— 单代理、按模块推进

小组报告的本节。由 W3 执行者撰写，交给汇总四节的人。除非注明出处，以下所有数据均测自本仓库。

英文版：`compx574/workflow-3/report-w3.md`（两版内容一致，以英文版为准）。

## 1. 这个工作流是什么

一个 Claude Code 代理，不使用子代理，按 `mealie/routes/` 的模块分九轮推进。每轮两条提示：

1. **只写契约。** 往 `compx574/workflow-3-contracts.md` 追加该模块一节 —— 每个接口的方法与完整路径、对应的 Python 源码行、JSON 字段名所依据的 schema 文件、id 如何存储与返回、由哪段 Python 检查把关、以及明确不实现什么。然后停止。
2. **只实现这一节。** 不碰其他模块。提交。停止。

执行者在两步之间审查。九轮，因此有九个审查点。

## 2. 工具、模型、成本、耗时、人工投入

以下数据全部读自本次工作的 Claude Code 会话记录（`~/.claude/projects/.../e60c4929-….jsonl`），非估算。

| 项目 | 值 |
|---|---|
| 工具 | Claude Code（CLI），单个主循环代理；提示中禁止子代理 |
| 模型 | **`claude-opus-5`** —— 全部 843 次助手回合均为该模型，全程未降级或切换 |
| 代理回合数 | 843 |
| 工具调用 | 366 次（Bash 364，AskUserQuestion 2） |
| **代理活跃工作时长** | **约 2.6 小时** —— 相邻事件间隔不超过 10 分钟的部分之和 |
| 墙钟跨度 | 48.9 小时，2026-10-10 01:26 → 2026-10-12 02:20 UTC |
| 等待执行者 | 46.3 小时，分 19 段，最长 17.9 小时（跨夜）。这段时间代理完全空闲 |
| 人工轮次 | 约 20 次：约 11 次用于环境搭建与方法设计，之后是 **9 次放行**，每个 gate 一次 |
| 人工审查深度 | 每个 gate 都以"继续"放行，未对契约逐条标注。日志中记录为 **agent-run**，与 W5 相同 |

**Token 用量**（同一来源，覆盖整个会话，含环境搭建与本报告的撰写）：

| | Token |
|---|---:|
| 输出 | 961,821 |
| 缓存写入 | 10,485,983 |
| 缓存读取 | 320,301,766 |
| 新鲜输入 | 1,686 |
| 合计 | 331,751,256 |

缓存读取占绝对多数 —— 这正是长单会话的形态：对话在每一回合以缓存读取的价位被重新读取，而不是以输入价位重新发送。**此处不给出金额**，因为金额取决于执行者的订阅方案而非仅取决于这些计数；若报告需要金额，Claude Code 的 `/usage` 是权威来源。

可比性说明：W5 报告的是每个子代理的 token 数（四个恢复运行的子代理合计 1,271,413），那是不同的单位 —— 那是四段短的委派运行，而非一个包含环境搭建、方法设计与报告撰写在内的 843 回合会话。两个数字放进同一列时必须加注说明。

逐轮工作量的量级参考：第 8、9 轮（执行者即时回复）端到端分别为 **17 分钟和 14 分钟**，含契约、实现、自测与计分。中等规模的轮次为 78–93 分钟的"代理 + 等待"合计时长。

## 3. 结果

使用 `compx574/workflow-3/measure.sh` 计分。该脚本是 `compx574/workflow-5/measure.sh` 的逐字拷贝，仅替换名称，因此 W3 与 W5 的计分口径一致。

| 构建 | Parity（状态码断言） | Body 准确率 |
|---|---|---|
| Phase 0 骨架，无路由 | 12/265 | — |
| **W3** | **265/265** | **43/43** |
| W5 | 189/265 | 37/40 |
| W1 | 263/265 | 15/40 |

逐轮：12 → 27 → 44 → 71 → 103 → 132 → 152 → 172 → 211 → 265。

W1 与 W5 的数据读自 `compx574/workflow-5/results/`。Body 准确率的分母不同（W5 的脚本抽样 40 条路径，共享脚本为 43 条），因此该列不可逐行对比。

## 4. Parity 检查实际测的是什么 —— 报告必须写明的局限

这是 W3 能为这次对比贡献的最重要一点，而且是测出来的，不是论证出来的。

**265 个计分请求中，Python 基准端只对 75 个返回 2xx。** 其余 190 个是拒绝路径：133 个 422、38 个 403、12 个 404、4 个 500，400/401/405 各一个。

按方法拆分：

| 方法 | 请求数 | 走到 2xx | 仅走拒绝路径 |
|---|---|---|---|
| GET | 121 | 73 | 48 |
| POST | 76 | 2 | 74 |
| PUT | 36 | 0 | 36 |
| DELETE | 29 | 0 | 29 |
| PATCH | 3 | 0 | 3 |
| **合计** | **265** | **75** | **190** |

**144 个请求（54%）是写操作，其中恰好只有 2 个走到成功路径。** 这是设计使然 —— `parity.hurl` 自己的头部注释写明，其余方法"发送会被拒绝的 body 或零 id，以便共享数据库保持原样"。其后果是：**该检查无法区分"实现了写操作的后端"与"没实现写操作的后端"**，三个工作流都是在这个盲区下被打分的。

对 W3 的具体含义：约一半的写路径未实现，返回 501。`compx574/workflow-3-contracts.md` 在"不实现什么"下逐条列出 —— 抓取与导入、图片与资源、归档处理、seeder、数据迁移器、邮件、事件总线，以及购物清单和菜谱背后的 service 层写入。

**另有 38 个通过项属于 `/api/admin/*`，该包的业务行为一行都未实现。** `run.sh` 签发的 token 属于 `bob`，非管理员，因此 `get_admin_user` 在 handler 执行前就拒绝了全部请求。对真正的管理员调用者，这份 PHP 是错的。这一点在分数上完全看不出来。

## 5. 代码质量 —— W3 产出的弱点所在

会上提到，部分生成的后端把业务逻辑放在 controller 中、缺少 service 层。**W3 的产出存在这个问题。**

| 度量 | 值 |
|---|---|
| 手写 PHP | 69 个文件，7,757 行（不含 `vendor/` 与 Laravel 脚手架） |
| 存在的层 | `app/Http/Controllers/`（38 个文件）、`app/Support/`（13 个文件）、`app/Auth/` |
| 缺失的层 | **没有 `app/Services/`，没有 `app/Repositories/`** |
| controller 内直接调用 `DB::connection(...)` | **100 处** |

Mealie 的 Python 明确采用 repository → service → controller 分层（`CLAUDE.md` 陈述了该模式，`mealie/repos/`、`mealie/services/` 实现了它）。W3 复现的是该架构的 **HTTP 表层**，而不是架构本身：controller 直接查询 SQLite，唯一的共享层是 `app/Support/` 下的一组序列化器与辅助类。

据执行者判断，成因是：每轮契约规定的是**接口行为** —— 路径、状态码、JSON 形状、权限闸门 —— 从未规定**内部结构**。代理优化的是契约所要求的、以及 parity 检查所奖励的，而这两者都不看分层。契约中加入一行"controller 不得直接访问 repository"很可能就会改变结果；这是对方法的廉价且可验证的改进，而非模型的局限。

**一个值得写进报告的自反现象。** 本组此前对 Mealie 的代码评审，把"service 层只是约定而非强制"列为候选问题之一 —— Python 自身的 controller 有时也会绕过 service 层。AI 重写不仅没有复制该分层，还以更强的形式复现了评审所指出的那个弱点。一个从可观察的 HTTP 行为出发工作的代理，只能继承到可被观察的那部分结构纪律，而分层恰恰不可观察。

设计上成立的部分：形状只定义一次并复用。第 5 轮为购物清单条目建立的 `ShoppingSerializer::unit/food`，第 6、8 轮原封不动地复用于菜谱配料以及 `/api/foods`、`/api/units`。`Pagination` 写于第 3 轮，此后每轮都在用。

## 6. 契约先行的方法究竟带来了什么

从日志可测得：在数轮中，契约在代码写出**之前**就捕获了错误 —— 代理在撰写契约时即对照运行中的 Python 基准端核验自己的断言，仅第 2 轮就修正了三处契约（注册校验器的优先级、`forgot-password` 的 null body、`reset-password` 的裸字符串 detail）。

此后实现仍需修正的轮次，由自测发现：

| 轮次 | 计分前发现的缺陷 |
|---|---|
| 3 | 4 处 —— JSON 斜杠/unicode 转义、storage 的数据来源、两种 404 形状、权限检查顺序 |
| 4 | 2 处 —— cookbook id 上的 `PUT`/`DELETE` 有三种从签名看不出的失败模式 |
| 5 | 1 处 —— `JsonResponse(null)` 渲染成 `{}` |
| 6 | 1 处 —— `recipeYield` 被强制成 `""` 而非 `null` |
| 8 | 1 处 —— 兄弟控制器行为不同：`categories/slug` 返 404，`tags/slug` 返 500 |
| 9 | 4 处 —— media 的 handler 根本不查数据库 |

值得写进报告的规律：**仅凭源码写出的契约，出错频率高到不可忽视，只有对照运行中的基准端实测才能改对。** 读 Python 是必要的，但不充分。

## 7. 其他工作流应当知道的测试方法注意事项

- **Hold-out。** `compx574/hurl/` 在实现工作中从未被读取或运行。每轮通过向 Python :9000 与 PHP :9001 发送同一请求并比对来自测（`sandbox/compare.sh`）；`measure.sh` 仅在轮次边界运行。一处泄漏记录为 D2a：环境搭建阶段，在采纳 hold-out 之前，统计过路径清单并读过前约 80 行。状态码断言未被读取。
- **自测可能污染共享数据库。** 第 5 轮就发生了：`ShoppingListCreate` 与 `PlanRulesCreate` 的每个字段都有默认值，因此 `POST` 空对象 `{}` 会返回 201 并插入一行。在跑完后的校验和比对发现之前，已创建 1 个购物清单和 1 条配餐规则；随后停止基准端并从 `dev/data/mealie.w3-snapshot.db` 还原。**给其他探测者的规则：探测用的 body 只有在 schema 存在必填字段时才是安全的。**
- **`sandbox/compare.sh` 只比较 body 的前 400 字节。** 它漏掉了第 6 轮的 `recipeYield` 缺陷，该缺陷是逐字段 diff 找到的。对长响应而言，它的 "identical" 计数不等于逐字节相同。
- **最终完整性**：`dev/data/mealie.db` 与 `compx574/mealie.db` 逐字节相同，`compx574/` 下的共享文件一个都未被修改。

## 8. 一条小组仍需验证的论断

报告很可能会论证 W1 的 263/265 来自对验收测试的针对性调优。**W3 未对此进行测量，无法为其提供支撑。** 整个过程中 `php-w1` 都被隔离在外。

W3 **确实测量到**的是（在 Python 侧）：`GET /api/admin/nonexistent` 携带非管理员 token 返回 **404** 而非 403，因为路由匹配发生在依赖解析之前。W3 复现了这一点 —— 每条路由都从 Python 源码声明而来，因此未声明的 admin 路径会返回 404。

`compx574/workflow-5/log.md` 记载：W1 将 `MatchPythonContract` 作为全局中间件前置，使得每个 `/api/admin*` 路径"无论路由是否存在"都返回 401/403；其 263 个通过项中有 153 个依赖该中间件；移除后 W1 降至 110/265。**应当有人直接对 `php-w1` 跑一次该探针**，让这一论断建立在测量而非引述之上。

## 9. 贡献记录

按作业要求，列出本成员的全部 mini-project 贡献：所有 PR（无论是否合并）、链接、简要说明、完成状态，以及背后的 issue 或缘由。

### 9.1 对上游 `mealie-recipes/mealie` 的贡献

| 编号 | 类型 | 标题 | 状态 | 链接 |
|---|---|---|---|---|
| 8306 | Issue | 删除仅作为菜谱替代品使用的 food，会留下孤立/损坏的引用且无任何警告 | **2026-09-06 修复并关闭**，由维护者 PR [#8311](https://github.com/mealie-recipes/mealie/pull/8311) 修复 | [issues/8306](https://github.com/mealie-recipes/mealie/issues/8306) |
| 8308 | Issue | 单个 food 的 description 为 NULL 会让整个 foods 列表接口崩溃（500），前端静默显示为"无数据" | **2026-09-06 修复并关闭**，由 PR [#8309](https://github.com/mealie-recipes/mealie/pull/8309) 修复 | [issues/8308](https://github.com/mealie-recipes/mealie/issues/8308) |
| 8111 | PR | fix: 防止 iOS 相机的重复照片覆盖菜谱步骤附件 | **2026-09-16 已合并** —— 3 commits，+52/−2，2 个文件 | [pull/8111](https://github.com/mealie-recipes/mealie/pull/8111) |
| 8487 | PR | fix: 购物清单条目在拖拽排序后回退到原顺序 | **开启中**，2026-09-21 创建 —— 1 commit，+16/−0，2 个文件 | [pull/8487](https://github.com/mealie-recipes/mealie/pull/8487) |

- **#8111** 源于在 iOS 上实际使用：相机对连续拍摄返回相同的文件名，导致第二张照片覆盖了菜谱步骤上的第一张。已被上游合并。
- **#8487** 是前端状态 bug —— 拖拽排序后购物清单会跳回之前的顺序。仍在等待维护者审阅，目前未收到修改要求。
- **#8306 与 #8308** 都是在 `ingredient_foods` 区域工作时发现的数据完整性 bug。两者**均非本成员修复**：提交、被三方接手，并**在同一天由他人已合并的 PR 修复** —— #8306 由维护者 `michael-genson` 在 [#8311](https://github.com/mealie-recipes/mealie/pull/8311) 中修复（"Thanks for the report! This will be fixed by…"），#8308 由贡献者 `chiliec` 在 [#8309](https://github.com/mealie-recipes/mealie/pull/8309) 中修复。此处的贡献是诊断与复现，不是补丁，报告应当如实写明 —— 当天即获维护者修复，本身也是报告清晰、可操作的合理佐证。

### 9.2 代码评审交付物

小组对 Mealie 的代码评审（2026 年 9 月提交）。本成员产出了小组报告据以撰写的证据档案 —— 对照提交 `56fbf6ad0` 核验的七个候选问题、五项正面设计选择、一份优先级排序，以及两条经核查后**被证伪**的常见批评（"前端没有测试" —— 实有 34 个测试文件；"CI 薄弱" —— 实有 sqlite + postgres 矩阵、真实 LDAP 容器、Ruff、mypy、CodeQL、Trivy、Playwright e2e）。

文件：`code-review-evidence.md`、`Mealie-Code-Review-Report-DRAFT.docx`（保存在仓库之外）。

### 9.3 Workflow 3 —— 本节的主题

| 项目 | 值 |
|---|---|
| PR | https://github.com/xiaofang2016/mealie/pull/2 |
| 标题 | `workflow-3: PHP rewrite of the Mealie API, single agent module by module` |
| 分支 | `RisonG:w3-single-agent` → `xiaofang2016:mealie-next-backend-rewrite` |
| 提交数 | 10 |
| 差异 | +41,065 / −0，162 个文件 |
| 状态 | **已合并** |
| 缘由 / issue | mini-project 的工作流对比；无上游 issue —— 这是 fork 上的课程作业，不拟提交至上游 Mealie |
| 交付物 | `php-w3/`（重写本体）；`compx574/workflow-3-contracts.md`（九份契约，约 1,500 行）；`compx574/workflow-3/log.md`（环境、九条偏离及其理由、逐轮时间线、最终对比）；`compx574/workflow-3/{measure.sh, accuracy_compare_w3.py, sandbox/, results/}`（计分工具，派生自 W5 的版本以保证数字可比） |

同样产出、但包含在该 PR 之内而非单独提 PR 的：`compx574/workflow-3/sandbox/{Dockerfile, score.sh, compare.sh, token.sh, restore-db.sh}` —— 测试脚手架。其中 `restore-db.sh` 与"探测 body 只有在 schema 有必填字段时才安全"这条规则，来自第 5 轮的事故，其他工作流可以复用。
