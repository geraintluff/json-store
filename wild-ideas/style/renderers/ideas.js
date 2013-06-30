Jsonary.render.register(Jsonary.plugins.Generator({
	rendererForData: function (data) {
		var tableConfig = {
			columns: [
			],
			titles: {
				"link-edit": "",
				"link-delete": ""
			},
			rowRenderHtml: function (data, context) {
				var result = '';
				if (context.uiState.editData) {
					result += this.defaultRowRenderHtml(context.uiState.editData, context);
				} else {
					result += this.defaultRowRenderHtml(data, context);
				}
				if (context.uiState.expand) {
					result += '<td class="json-array-table-full" colspan="' + this.columns.length + '">';
					result += context.renderHtml(data);
					result += '</td>';
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
			rowAction: function (data, context, actionName) {
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
				} else if (actionName == "delete") {
					context.uiState.deleteConfirm = true;
				} else if (actionName == "delete-confirm") {
					var deleteLink = data.getLink("delete");
					if (deleteLink) {
						deleteLink.follow(undefined, false);
					}
					delete context.uiState.deleteConfirm;
				} else if (actionName == "delete-cancel") {
					delete context.uiState.deleteConfirm;
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
		function addColumnsFromLink(link) {
			
		}
		var itemSchemas = data.schemas().indexSchemas(0).getFull();
		if (data.readOnly()) {
			if (itemSchemas.links('delete').length > 0) {
				tableConfig.columns.push('link-delete');
			}
			if (itemSchemas.links('edit').length > 0) {
				tableConfig.columns.push('link-edit');
			}
			var links = itemSchemas.links();
			for (var i = 0; i < links.length; i++) {
				var link = links[i];
				if (link.rel != "delete" && link.rel != "edit") {
					addColumnsFromLink(link);
				}
			}
		}
		addColumnsFromSchemas(itemSchemas);
		return new Jsonary.plugins.TableRenderer(tableConfig);
	},
	filter: function (data, schemas) {
		return schemas.containsUrl('json/schemas/idea#/definitions/array');
	}
}));