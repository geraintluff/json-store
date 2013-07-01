Jsonary.render.register(Jsonary.plugins.Generator({
	rendererForData: function (data) {
		var tableConfig = {
			columns: [
			],
			titles: {
			},
			rowRenderHtml: function (data, context) {
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
						if (link.rel == "edit") {
							result += this.defaultRowRenderHtml(context.uiState.linkData, context);
						} else {
							result += this.defaultRowRenderHtml(data, context);
							result += '<td class="json-array-table-full" colspan="' + this.columns.length + '">';
							result += context.actionHtml('<span class="button action">confirm</span>', 'link-confirm', context.uiState.linkRel, context.uiState.linkIndex);
							result += context.actionHtml('<span class="button action">cancel</span>', 'link-cancel');
							result += context.renderHtml(context.uiState.linkData);
							result += '</td>';
						}
					} else {
						result += this.defaultRowRenderHtml(data, context);
						result += '<td class="json-array-table-full" colspan="' + this.columns.length + '">';
						result += context.actionHtml('<span class="button action">confirm</span>', 'link-confirm', context.uiState.linkRel, context.uiState.linkIndex);
						result += context.actionHtml('<span class="button action">cancel</span>', 'link-cancel');
						result += '</td>';
					}
				} else {
					result += this.defaultRowRenderHtml(data, context);
				}
				return result;
			},
			defaultCellRenderHtml: function (data, context) {
				var result = '<td style="position: relative">';
				result += context.renderHtml(data);
				if (data.readOnly()) {
					result += context.parent.actionHtml('<div style="position: absolute; left: 0; right: 0; top: 0; bottom: 0"></div>', 'expand');
				}
				return result + '</td>';
			},
			cellRenderHtml: {
				"link-edit": function (data, context) {
					var result = '<td>';
					if (context.parent.uiState.editData) {
						result += context.parent.actionHtml('save', 'edit-save');
					} else if (data.readOnly() && data.getLink("edit")) {
						result += context.parent.actionHtml('edit', 'edit');
					}
					return result + '</td>';
				},
				"link-delete": function (data, context) {
					var result = '<td>';
					if (data.readOnly() && data.getLink('delete')) {
						if (context.parent.uiState.deleteConfirm) {
							result += '<span class="dialog-anchor">';
							result += context.parent.actionHtml('<div class="dialog-overlay"></div>', 'delete-cancel');
							result += '<div class="dialog-box"><div class="dialog-title">Confirm delete</div>';
							result += context.parent.actionHtml('<span class="button action">delete</span>', 'delete-confirm');
							result += context.parent.actionHtml('<span class="button action">cancel</span>', 'delete-cancel');
							result += '</div>';
							result += '</span>';
						}
						result += context.parent.actionHtml('delete', 'delete');
					}
					return result + '</td>';
				}
			},
			rowAction: function (data, context, actionName, arg1, arg2) {
				if (actionName == "expand") {
					if (context.uiState.expand) {
						delete context.uiState.expand;
					} else {
						context.uiState.expand = true;
					}
				} else if (actionName == "edit") {
					context.uiState.editData = data.editableCopy();
				} else if (actionName == "edit-save") {
					var editLink = data.getLink('edit');
					if (editLink) {
						editLink.follow(context.uiState.editData, false);
					}
					delete context.uiState.editData;
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
					context.uiState.linkRel = linkRel;
					context.uiState.linkIndex = linkIndex;
					var link = data.links(linkRel)[linkIndex || 0];
					if (link) {
						link.follow(context.uiState.linkData, false);
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
			}
		};
		var columnsObj = {};
		function addColumnsFromSchemas(schemas, pathPrefix) {
			schemas = schemas.getFull();
			pathPrefix = pathPrefix || "";
			var basicTypes = schemas.basicTypes();
			
			if (basicTypes.length != 1 || basicTypes[0] != "object") {
				var column = pathPrefix;
				if (!columnsObj[column]) {
					tableConfig.columns.push(column);
					columnsObj[column] = true;
					tableConfig.cellRenderHtml[column] = function (data, context) {
						if (data.basicType() == "object") {
							return '<td></td>';
						} else {
							return this.defaultCellRenderHtml(data, context);
						}
					};
				}
			}

			if (basicTypes.indexOf('object') != -1) {
				var knownProperties = schemas.knownProperties();
				for (var i = 0; i < knownProperties.length; i++) {
					var key = knownProperties[i];
					addColumnsFromSchemas(schemas.propertySchemas(key), pathPrefix + Jsonary.joinPointer([key]));
				}
			}
		}
		function addColumnsFromLink(linkDefinition, index) {
			var columnName = "link$" + index + "$" + linkDefinition.rel;
			tableConfig.titles[columnName] = linkDefinition.title || linkDefinition.rel();
			tableConfig.columns.push(columnName);
			
			var confirmAction = "link-confirm", confirmTitle = null;
			if (linkDefinition.rel() == "edit") {
				confirmTitle = "save";
			}
			
			tableConfig.cellRenderHtml[columnName] = function (data, context) {
				var result = '<td>';
				if (!context.parent.uiState.linkRel) {
					var links = data.links(linkDefinition.rel());
					for (var i = 0; i < links.length; i++) {
						var link = links[i];
						if (link.definition == linkDefinition) {
							result += context.parent.actionHtml(link.title || link.rel, 'link', link.rel, i || undefined);
						}
					}
				} else if (confirmTitle) {
					var link = data.links(context.parent.uiState.linkRel)[context.parent.uiState.linkIndex || 0];
					if (link.definition == linkDefinition) {
						result += context.parent.actionHtml(confirmTitle, confirmAction, link.rel, context.parent.uiState.linkIndex);
					}
				}
				return result + '</td>';
			};
		}
		var itemSchemas = data.schemas().indexSchemas(0).getFull();
		if (data.readOnly()) {
			var links = itemSchemas.links();
			for (var i = 0; i < links.length; i++) {
				var link = links[i];
				addColumnsFromLink(link, i);
			}
		}
		addColumnsFromSchemas(itemSchemas);
		return new Jsonary.plugins.TableRenderer(tableConfig);
	},
	filter: function (data, schemas) {
		return schemas.containsUrl('json/schemas/idea#/definitions/array');
	}
}));