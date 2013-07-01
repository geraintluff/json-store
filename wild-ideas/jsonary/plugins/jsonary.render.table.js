(function (Jsonary) {
	function TableRenderer (config) {
		var thisRenderer = this;
		
		config = config || {};
		this.config = config;
		
		config.columns = config.columns || [];
		config.titles = config.titles || {};
		config.titleHtml = config.titleHtml || {};
		config.defaultTitleHtml = config.defaultTitleHtml || function (data, context, columnPath) {
			return '<th>' + Jsonary.escapeHtml(this.titles[columnPath] != undefined ? this.titles[columnPath] : columnPath) + '</th>';
		};
		config.cellRenderHtml = config.cellRenderHtml || {};
		config.defaultCellRenderHtml = config.defaultCellRenderHtml || function (cellData, context) {
			return '<td>' + context.renderHtml(cellData) + '</td>';
		};
		config.defaultRenderHtml = function (data, context) {
			return thisRenderer.tableRenderHtml(data, context);
		}
		config.defaultRowRenderHtml = function (rowData, context) {
			var result = "<tr>";
			for (var i = 0; i < config.columns.length; i++) {
				var columnPath = config.columns[i];
				var cellData = (columnPath == "" || columnPath.charAt(0) == "/") ? rowData.subPath(columnPath) : rowData;
				var cellRenderHtml = config.cellRenderHtml[columnPath] || config.defaultCellRenderHtml;
				result += cellRenderHtml.call(this, cellData, thisRenderer.cellContext(cellData, context, columnPath));
			}
			result += '</tr>';
			return result;
		};

		config.classes = config.classes || {};
		config.classes.table = config.classes.table || "json-array-table";
		
		if (config.filter) {
			this.filter = function (data, schemas) {
				return config.filter(data, schemas);
			};
		}
		
		this.addColumn = function (key, title, renderHtml) {
			config.columns.push(key);
			if (typeof title == 'function') {
				config.titleHtml[key] = title;
			} else if (title != undefined) {
				config.titles[key] = title;
			}
			if (renderHtml) {
				config.cellRenderHtml[key] = renderHtml;
			}
			return this;
		}
		
		this.component = config.component;
	};
	TableRenderer.prototype = {
		action: function (context, actionName) {
			if (context.cellData) {
				var columnPath = context.columnPath;
				var cellAction = this.config.cellAction[columnPath];
				var newArgs = [context.cellData];
				while (newArgs.length <= arguments.length) {
					newArgs.push(arguments[newArgs.length - 1]);
				}
				return cellAction.apply(this.config, newArgs);
			} else if (context.rowData) {
				var rowAction = this.config.rowAction;
				var newArgs = [context.rowData];
				while (newArgs.length <= arguments.length) {
					newArgs.push(arguments[newArgs.length - 1]);
				}
				return rowAction.apply(this.config, newArgs);
			}
			return this.config.action.apply(this.config, arguments);
		},
		rowContext: function (data, context) {
			var subContext = context.subContext(data);
			subContext.rowData = data;
			return subContext;
		},
		cellContext: function (data, context, columnPath) {
			var subContext = context.subContext('col' + columnPath);
			subContext.columnPath = columnPath;
			subContext.cellData = data;
			return subContext;
		},
		renderHtml: function (data, context) {
			var config = this.config;
			if (config.renderHtml) {
				return config.renderHtml(data, context);
			} else {
				return config.defaultRenderHtml(data, context);
			}
		},
		rowRenderHtml: function (data, context) {
			var config = this.config;
			if (config.rowRenderHtml) {
				return config.rowRenderHtml(data, context);
			} else {
				return config.defaultRowRenderHtml(data, context);
			}
		},
		tableRenderHtml: function (data, context) {
			var thisRenderer = this;
			var config = this.config;
			var result = '';
			result += '<table class="' + config.classes.table + '">';
			result += '<thead><tr>';
			for (var i = 0; i < config.columns.length; i++) {
				var columnPath = config.columns[i];
				var titleHtml = config.titleHtml[columnPath] || config.defaultTitleHtml;
				if (typeof titleHtml == 'function') {
					var titleContext = context.subContext('title' + columnPath);
					titleContext.columnPath = titleContext;
					titleHtml = titleHtml.call(config, data, titleContext, columnPath);
				}
				result += titleHtml;
			}
			result += '</tr></thead>';
			result += '<tbody>'
			data.items(function (index, rowData) {
				var rowContext = thisRenderer.rowContext(rowData, context);
				result += thisRenderer.rowRenderHtml(rowData, rowContext);
			});
			result += '</tbody>';
			result += '</table>';
			return result;
		},
		enhance: function (element, data, context) {
			if (this.config.enhance) {
				return this.config.enhance(element, data, context);
			} else if (this.config.render) {
				return this.config.render(element, data, context);
			}
		}
	};
	TableRenderer.register = function (obj) {
		var renderer = new TableRenderer(obj);
		return Jsonary.render.register(renderer);
	};
	
	function LinkTableRenderer(config) {
		config = config || {};
		config.rowRenderHtml = function (data, context) {
			var result = '';
			if (context.uiState.expand) {
				result += this.defaultRowRenderHtml(data, context);
				result += '<td class="json-array-table-full" colspan="' + this.columns.length + '">';
				if (context.uiState.expand === true) {
					result += context.renderHtml(data);
				} else {
					result += context.renderHtml(context.uiState.expand);
				}
				result += '</td>';
			} else if (context.uiState.linkRel) {
				var link = data.links(context.uiState.linkRel)[context.uiState.linkIndex || 0];
				if (context.uiState.linkData) {
					if (link.rel == "edit" && link.submissionSchemas.length == 0) {
						result += this.defaultRowRenderHtml(context.uiState.linkData, context);
					} else {
						result += this.defaultRowRenderHtml(data, context);
						result += '<td class="json-array-table-full" colspan="' + this.columns.length + '">';
						result += '<div class="json-array-table-full-title">' + Jsonary.escapeHtml(link.title || link.rel) + '</div>';
						result += '<div class="json-array-table-full-buttons">';
						result += context.actionHtml('<span class="button action">confirm</span>', 'link-confirm', context.uiState.linkRel, context.uiState.linkIndex);
						result += context.actionHtml(' <span class="button action">cancel</span>', 'link-cancel');
						result += '</div>';
						result += context.renderHtml(context.uiState.linkData);
						result += '</td>';
					}
				} else {
					result += this.defaultRowRenderHtml(data, context);
					result += '<td class="json-array-table-full" colspan="' + this.columns.length + '">';
					result += '<div class="json-array-table-full-title">' + Jsonary.escapeHtml(link.title || link.rel) + '</div>';
						result += '<div class="json-array-table-full-buttons">';
					result += context.actionHtml('<span class="button action">confirm</span>', 'link-confirm', context.uiState.linkRel, context.uiState.linkIndex);
					result += context.actionHtml(' <span class="button action">cancel</span>', 'link-cancel');
						result += '</div>';
					result += '</td>';
				}
			} else {
				result += this.defaultRowRenderHtml(data, context);
			}
			return result;
		};
		config.rowAction = function (data, context, actionName, arg1, arg2) {
			if (actionName == "expand") {
				if (context.uiState.expand) {
					delete context.uiState.expand;
				} else {
					context.uiState.expand = true;
				}
			} else if (actionName == "link") {

				var linkRel = arg1, linkIndex = arg2
				var link = data.links(linkRel)[linkIndex || 0];
				if (link.submissionSchemas.length) {
					context.uiState.linkRel = linkRel;
					context.uiState.linkIndex = linkIndex;
					var linkData = Jsonary.create();
					linkData.addSchema(link.submissionSchemas);
					context.uiState.linkData = linkData;
					link.submissionSchemas.createValue(function (value) {
						linkData.setValue(value);
					});
					delete context.uiState.expand;
				} else if (link.rel == "edit") {
					context.uiState.linkRel = linkRel;
					context.uiState.linkIndex = linkIndex;
					context.uiState.linkData = data.editableCopy();
					delete context.uiState.expand;
				} else if (link.method != "GET") {
					context.uiState.linkRel = linkRel;
					context.uiState.linkIndex = linkIndex;
					delete context.uiState.linkData;
					delete context.uiState.expand;
				} else {
					var targetExpand = (link.rel == "self") ? true : link.href;
					if (context.uiState.expand == targetExpand) {
						delete context.uiState.expand;
					} else {
						context.uiState.expand = targetExpand;
					}
				}
			} else if (actionName == "link-confirm") {
				var linkRel = arg1, linkIndex = arg2
				var link = data.links(linkRel)[linkIndex || 0];
				if (link) {
					link.follow(context.uiState.linkData, this.linkHandler);
				}
				delete context.uiState.linkRel;
				delete context.uiState.linkIndex;
				delete context.uiState.linkData;
				delete context.uiState.expand;
			} else if (actionName == "link-cancel") {
				delete context.uiState.linkRel;
				delete context.uiState.linkIndex;
				delete context.uiState.linkData;
				delete context.uiState.expand;
			}
			return true;
		};
		config.linkHandler = config.linkHandler || function () {};
		TableRenderer.call(this, config);
	}
	LinkTableRenderer.prototype = Object.create(TableRenderer.prototype);
	LinkTableRenderer.prototype.addLinkColumn = function (linkRel, title, linkHtml, activeHtml, isConfirm) {
		if (typeof linkRel == "string") {
			var columnName = "link$" + linkRel;
			
			this.addColumn(columnName, title, function (data, context) {
				var result = '<td>';
				if (!context.parent.uiState.linkRel) {
					var link = data.links(linkRel)[0];
					if (link) {
						result += context.parent.actionHtml(linkHtml, 'link', linkRel);
					}
				} else if (activeHtml) {
					var activeLink = data.links(context.parent.uiState.linkRel)[context.parent.uiState.linkIndex || 0];
					if (activeLink.rel == linkRel) {
						if (isConfirm) {
							result += context.parent.actionHtml(activeHtml, 'link-confirm', context.parent.uiState.linkRel, context.parent.uiState.linkIndex);
						} else {
							result += context.parent.actionHtml(activeHtml, 'link-cancel');
						}
					}
				}
				return result + '</td>';
			});
		} else {
			var linkDefinition = linkRel;
			linkRel = linkDefinition.rel();
			var columnName = "link$" + linkRel + "$" + linkHtml;
			this.addColumn(columnName, title, function (data, context) {
				var result = '<td>';
				if (!context.parent.uiState.linkRel) {
					var links = data.links(linkRel);
					for (var i = 0; i < links.length; i++) {
						var link = links[i];
						if (link.definition = linkDefinition) {
							result += context.parent.actionHtml(linkHtml, 'link', linkRel, i);
						}
					}
				} else if (activeHtml) {
					var activeLink = data.links(context.parent.uiState.linkRel)[context.parent.uiState.linkIndex || 0];
					if (activeLink.definition == linkDefinition) {
						if (isConfirm) {
							result += context.parent.actionHtml(activeHtml, 'link-confirm', context.parent.uiState.linkRel, context.parent.uiState.linkIndex);
						} else {
							result += context.parent.actionHtml(activeHtml, 'link-cancel');
						}
					}
				}
				return result + '</td>';
			});
		}
		return this;
	};
	LinkTableRenderer.register = function (obj) {
		var renderer = new LinkTableRenderer(obj);
		return Jsonary.render.register(renderer);
	};
	
	Jsonary.plugins = Jsonary.plugins || {};
	Jsonary.plugins.TableRenderer = TableRenderer;
	Jsonary.plugins.LinkTableRenderer = LinkTableRenderer;
})(Jsonary);